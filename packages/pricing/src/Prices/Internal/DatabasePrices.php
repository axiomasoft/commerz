<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Pricing\Common\Internal\Clock;
use Commerz\Pricing\Common\Internal\Rows;
use Commerz\Pricing\Common\Internal\Windows;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use Commerz\Pricing\Prices\Contracts\Prices;
use Commerz\Pricing\Prices\Data\ExplainPrice;
use Commerz\Pricing\Prices\Data\ListPrices;
use Commerz\Pricing\Prices\Data\PriceExplanation;
use Commerz\Pricing\Prices\Data\PriceId;
use Commerz\Pricing\Prices\Data\PriceView;
use Commerz\Pricing\Prices\Data\RemovePrice;
use Commerz\Pricing\Prices\Data\ReschedulePrice;
use Commerz\Pricing\Prices\Data\SetPrice;
use Commerz\Pricing\Prices\Data\SetPrices;
use Commerz\Pricing\Prices\Events\PricesChanged;
use Commerz\Pricing\Prices\Exceptions\PriceNotFoundById;
use Commerz\Pricing\Prices\Exceptions\PriceWindowOverlap;
use Commerz\Schema\TableNames;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Packman\Attributes\Internal;

/**
 * Lock order: the price list row `for share` first, then price rows (`for no key update`); batches take the price
 * rows in a fixed order (`PriceWrites::compare`).
 */
#[Internal]
final readonly class DatabasePrices implements Prices
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private CommittedEvents $events,
        private PriceWrites $writes,
        private DatabasePriceResolver $resolver,
    ) {}

    public function set(SetPrice $command): PriceView
    {
        return $this->transactions->run(function () use ($command): PriceView {
            $list = $this->writes->lockList($command->priceList);
            $id = $this->writes->upsert($list, $command);

            $this->events->dispatch(new PricesChanged($command->priceList, [(string) $command->sellable]));

            return $this->views([$id])[$id];
        });
    }

    public function setMany(SetPrices $command): array
    {
        $seen = [];

        foreach ($command->prices as $price) {
            $identity = PriceWrites::identity($price);

            if (isset($seen[$identity])) {
                throw new InvalidCommand('prices', "'{$price->sellable}' is listed twice with the same key and window", 'pricing.duplicate_price');
            }

            $seen[$identity] = true;
        }

        return $this->transactions->run(function () use ($command): array {
            $list = $this->writes->lockList($command->priceList);
            $order = $command->prices;
            uasort($order, PriceWrites::compare(...));

            $ids = [];
            $sellables = [];

            foreach ($order as $position => $price) {
                $ids[$position] = $this->writes->upsert($list, $price);
                $sellables[(string) $price->sellable] = true;
            }

            $this->events->dispatch(new PricesChanged($command->priceList, array_map(strval(...), array_keys($sellables))));

            ksort($ids);
            $views = $this->views(array_values($ids));

            return array_map(static fn (string $id): PriceView => $views[$id], array_values($ids));
        });
    }

    public function reschedule(ReschedulePrice $command): PriceView
    {
        return $this->transactions->run(function () use ($command): PriceView {
            $db = $this->db();
            $table = $this->tables->table('prices');
            $row = $db->table($table)->where('id', $command->price->value)->first()
                ?? throw new PriceNotFoundById($command->price->value);
            $priceList = new PriceListId(Rows::string($row, 'price_list_id'));

            // The list, then the key, then the price row, as in `set`.
            $this->writes->lockList($priceList);
            $this->writes->lockKey(
                $priceList->value,
                Rows::string($row, 'sellable_ref'),
                Rows::nullableString($row, 'customer_group'),
                Rows::int($row, 'min_quantity'),
            );

            $row = $db->table($table)->where('id', $command->price->value)->lock('for no key update')->first()
                ?? throw new PriceNotFoundById($command->price->value);

            try {
                $db->transaction(fn () => $db->table($table)->where('id', $command->price->value)->update([
                    'valid_from' => PriceWrites::format($command->validFrom),
                    'valid_until' => PriceWrites::format($command->validUntil),
                    'updated_at' => Clock::now(),
                ]));
            } catch (QueryException $e) {
                if (! Windows::isExclusionViolation($e)) {
                    throw $e;
                }

                throw new PriceWindowOverlap(
                    Rows::string($row, 'sellable_ref'),
                    $priceList->value,
                    $this->writes->conflicting(
                        $priceList->value,
                        Rows::string($row, 'sellable_ref'),
                        Rows::nullableString($row, 'customer_group'),
                        Rows::int($row, 'min_quantity'),
                        $command->validFrom,
                        $command->validUntil,
                        $command->price->value,
                    ),
                );
            }

            $this->events->dispatch(new PricesChanged($priceList, [Rows::string($row, 'sellable_ref')]));

            return $this->views([$command->price->value])[$command->price->value];
        });
    }

    public function remove(RemovePrice $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $db = $this->db();
            $table = $this->tables->table('prices');
            $row = $db->table($table)->where('id', $command->price->value)->lock('for no key update')->first();

            if ($row === null) {
                return;
            }

            $db->table($table)->where('id', $command->price->value)->delete();
            $this->events->dispatch(new PricesChanged(
                new PriceListId(Rows::string($row, 'price_list_id')),
                [Rows::string($row, 'sellable_ref')],
            ));
        });
    }

    public function list(ListPrices $query): Page
    {
        $builder = $this->db()->table($this->tables->table('prices'))
            ->where('price_list_id', $query->priceList->value)
            ->orderBy('sellable_ref')
            ->orderBy('id')
            ->limit($query->first + 1);

        if ($query->sellable instanceof SellableRef) {
            $builder->where('sellable_ref', (string) $query->sellable);
        }

        if ($query->after instanceof Cursor) {
            [$sellable, $id] = self::position($query->after);
            $builder->where(static fn (Builder $nested): Builder => $nested
                ->where('sellable_ref', '>', $sellable)
                ->orWhere(static fn (Builder $tie): Builder => $tie->where('sellable_ref', $sellable)->where('id', '>', $id)));
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->first;
        $rows = array_slice($rows, 0, $query->first);
        $views = array_map(self::view(...), $rows);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];

        return new Page($views, $more && $last !== null
            ? Cursor::after(Rows::string($last, 'sellable_ref').'|'.Rows::string($last, 'id'))
            : null);
    }

    public function resolveMany(array $requests, CommerceContext $context): array
    {
        return $this->resolver->resolveMany($requests, $context);
    }

    public function explain(ExplainPrice $query): PriceExplanation
    {
        return $this->resolver->explain($query);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, PriceView> by price id
     */
    private function views(array $ids): array
    {
        $views = [];

        foreach ($this->db()->table($this->tables->table('prices'))->whereIn('id', $ids)->get() as $row) {
            $views[Rows::string($row, 'id')] = self::view($row);
        }

        return $views;
    }

    /** @return array{string, string} sellable ref and price id of a list cursor */
    private static function position(Cursor $cursor): array
    {
        $position = $cursor->position();
        $id = substr($position, -36);
        $sellable = substr($position, 0, -37);

        if (strlen($position) < 38 || $position[-37] !== '|' || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $id) !== 1) {
            throw new InvalidCommand('after', 'is not a valid cursor', 'common.invalid_cursor');
        }

        return [$sellable, $id];
    }

    private static function view(object $row): PriceView
    {
        $currency = Rows::string($row, 'currency');
        $compareAt = Rows::nullableInt($row, 'compare_at_minor');
        $from = Rows::nullableString($row, 'valid_from');
        $until = Rows::nullableString($row, 'valid_until');

        return new PriceView(
            new PriceId(Rows::string($row, 'id')),
            new PriceListId(Rows::string($row, 'price_list_id')),
            SellableRef::fromString(Rows::string($row, 'sellable_ref')),
            Rows::nullableString($row, 'customer_group'),
            Rows::int($row, 'min_quantity'),
            Money::ofMinor(Rows::int($row, 'amount_minor'), $currency),
            $compareAt === null ? null : Money::ofMinor($compareAt, $currency),
            $from === null ? null : new DateTimeImmutable($from),
            $until === null ? null : new DateTimeImmutable($until),
            Rows::date($row, 'created_at'),
            Rows::date($row, 'updated_at'),
        );
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
