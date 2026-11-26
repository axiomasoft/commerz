<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\Uuid7;
use Commerz\Pricing\Common\Internal\Windows;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use Commerz\Pricing\PriceLists\Enums\PriceListStatus;
use Commerz\Pricing\PriceLists\Exceptions\PriceListArchived;
use Commerz\Pricing\PriceLists\Exceptions\PriceListNotFound;
use Commerz\Pricing\Prices\Data\SetPrice;
use Commerz\Pricing\Prices\Enums\PriceHistoryEvent;
use Commerz\Pricing\Prices\Exceptions\PriceWindowOverlap;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\PgTimestamp;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use Packman\Attributes\Internal;

/**
 * The write steps of a price, shared by `set`, `setMany` and `reschedule` (PRC-01). PostgreSQL cannot `ON CONFLICT
 * DO UPDATE` through an exclusion constraint, so the upsert is: find the exact key and window and lock it, else insert
 * in a savepoint; a violation means a concurrent writer got there first, so look once more before reporting an overlap.
 *
 * Writers of one key (list, sellable, group, quantity step) queue on a transaction advisory lock first. Without it, two
 * inserts of overlapping windows both put their tuple in the index before either checks the constraint, each waits
 * for the other and PostgreSQL aborts one with a deadlock instead of the 23P01 this code answers.
 */
#[Internal]
final readonly class PriceWrites
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private ?PriceHistoryJournal $history = null,
    ) {}

    /**
     * Holds the price list `for share`: archiving it (`for no key update`) waits, so no price enters an archived list.
     *
     * @throws PriceListNotFound
     * @throws PriceListArchived
     */
    public function lockList(PriceListId $id): object
    {
        $row = $this->db()->table($this->tables->table('price_lists'))
            ->where('id', $id->value)
            ->lock('for share')
            ->first();

        if ($row === null) {
            throw new PriceListNotFound($id->value);
        }

        if (Rows::string($row, 'status') === PriceListStatus::Archived->value) {
            throw new PriceListArchived(Rows::string($row, 'code'));
        }

        return $row;
    }

    /**
     * @param  object  $list  the row returned by `lockList`
     * @return string id of the inserted or updated price
     *
     * @throws InvalidCommand the currency is not the one of the list
     * @throws PriceWindowOverlap
     */
    public function upsert(object $list, SetPrice $command): string
    {
        if ($command->amount->currency->code !== Rows::string($list, 'currency')) {
            throw new InvalidCommand(
                'amount',
                'must be in '.Rows::string($list, 'currency').", the currency of price list '".Rows::string($list, 'code')."'",
                'pricing.currency_mismatch',
            );
        }

        $this->lockKey($command->priceList->value, (string) $command->sellable, $command->customerGroup, $command->minQuantity);

        $existing = $this->exact($command);

        if ($existing !== null) {
            $this->updateAmount($existing, $command);
            $this->recordHistory($existing, $command);

            return $existing;
        }

        $db = $this->db();
        $id = Uuid7::generate();
        $now = PgTimestamp::now();

        try {
            // A savepoint: the violation must not poison the transaction of the caller.
            $db->transaction(fn () => $db->table($this->tables->table('prices'))->insert([
                'id' => $id,
                'price_list_id' => $command->priceList->value,
                'sellable_ref' => (string) $command->sellable,
                'customer_group' => $command->customerGroup,
                'min_quantity' => $command->minQuantity,
                'amount_minor' => $command->amount->amountMinor,
                'compare_at_minor' => $command->compareAt?->amountMinor,
                'currency' => $command->amount->currency->code,
                'valid_from' => self::format($command->validFrom),
                'valid_until' => self::format($command->validUntil),
                'created_at' => $now,
                'updated_at' => $now,
            ]));
            $this->recordHistory($id, $command);

            return $id;
        } catch (QueryException $e) {
            if (! Windows::isExclusionViolation($e)) {
                throw $e;
            }
        }

        // The inserts of two identical commands collide; the one that waited sees the committed row of the other.
        $existing = $this->exact($command);

        if ($existing !== null) {
            $this->updateAmount($existing, $command);
            $this->recordHistory($existing, $command);

            return $existing;
        }

        throw new PriceWindowOverlap(
            (string) $command->sellable,
            $command->priceList->value,
            $this->conflicting(
                $command->priceList->value,
                (string) $command->sellable,
                $command->customerGroup,
                $command->minQuantity,
                $command->validFrom,
                $command->validUntil,
                null,
            ),
        );
    }

    /** Queues the writers of one price key until this transaction ends. */
    public function lockKey(string $priceList, string $sellable, ?string $group, int $minQuantity): void
    {
        // The sellable comes last: the fields before it cannot contain the separator.
        Windows::lock($this->db(), "commerz.price:{$priceList}|{$group}|{$minQuantity}|{$sellable}");
    }

    /**
     * Another price of the same key whose window meets `[from, until)`, the lowest id first.
     *
     * @return string|null id of the conflicting price
     */
    public function conflicting(
        string $priceList,
        string $sellable,
        ?string $group,
        int $minQuantity,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $until,
        ?string $exceptPrice,
    ): ?string {
        $builder = $this->db()->table($this->tables->table('prices'))
            ->where('price_list_id', $priceList)
            ->where('sellable_ref', $sellable)
            ->whereRaw('customer_group is not distinct from ?', [$group])
            ->where('min_quantity', $minQuantity)
            ->whereRaw("tstzrange(valid_from, valid_until, '[)') && tstzrange(?::timestamptz, ?::timestamptz, '[)')", [self::format($from), self::format($until)])
            ->orderBy('id');

        if ($exceptPrice !== null) {
            $builder->where('id', '<>', $exceptPrice);
        }

        $id = $builder->value('id');

        return is_string($id) ? $id : null;
    }

    /** The text of a timestamp as the `timestamptz(6)` columns take it; a missing bound stays open. */
    public static function format(?DateTimeImmutable $at): ?string
    {
        return Windows::format($at);
    }

    /** Identifies a price by key and window: two commands of one batch with the same identity are a duplicate. */
    public static function identity(SetPrice $command): string
    {
        return implode("\0", [
            (string) $command->sellable,
            $command->customerGroup ?? '',
            $command->minQuantity,
            self::format($command->validFrom) ?? '-inf',
            self::format($command->validUntil) ?? '+inf',
        ]);
    }

    /** The order every batch takes its locks in, so concurrent batches queue instead of deadlocking. */
    public static function compare(SetPrice $a, SetPrice $b): int
    {
        foreach ([
            strcmp((string) $a->sellable, (string) $b->sellable),
            strcmp($a->customerGroup ?? '', $b->customerGroup ?? ''),
            $a->minQuantity <=> $b->minQuantity,
            strcmp(self::bound($a->validFrom), self::bound($b->validFrom)),
            strcmp(self::bound($a->validUntil), self::bound($b->validUntil)),
        ] as $order) {
            if ($order !== 0) {
                return $order <=> 0;
            }
        }

        return 0;
    }

    /** UTC text sorts as time; an open bound is the empty text and sorts first. */
    private static function bound(?DateTimeImmutable $at): string
    {
        return self::format($at) ?? '';
    }

    private function exact(SetPrice $command): ?string
    {
        $db = $this->db();
        $builder = $db->table($this->tables->table('prices'))
            ->where('price_list_id', $command->priceList->value)
            ->where('sellable_ref', (string) $command->sellable)
            ->where('min_quantity', $command->minQuantity);

        if ($command->customerGroup === null) {
            $builder->whereNull('customer_group');
        } else {
            $builder->where('customer_group', $command->customerGroup);
        }

        $from = self::format($command->validFrom);
        $until = self::format($command->validUntil);

        if (! $db instanceof Connection || $db->getDriverName() === 'pgsql') {
            $builder->whereRaw('valid_from is not distinct from ?::timestamptz', [$from])
                ->whereRaw('valid_until is not distinct from ?::timestamptz', [$until]);
        } else {
            $from === null ? $builder->whereNull('valid_from') : $builder->where('valid_from', $from);
            $until === null ? $builder->whereNull('valid_until') : $builder->where('valid_until', $until);
        }

        $id = $builder->lock('for no key update')->value('id');

        return is_string($id) ? $id : null;
    }

    private function recordHistory(string $id, SetPrice $command): void
    {
        $this->history?->record(
            priceId: $id,
            priceListId: $command->priceList->value,
            sellableRef: (string) $command->sellable,
            customerGroup: $command->customerGroup,
            minQuantity: $command->minQuantity,
            amountMinor: $command->amount->amountMinor,
            compareAtMinor: $command->compareAt?->amountMinor,
            currency: $command->amount->currency->code,
            event: PriceHistoryEvent::Set,
            validFrom: $command->validFrom,
            validUntil: $command->validUntil,
        );
    }

    private function updateAmount(string $id, SetPrice $command): void
    {
        $this->db()->table($this->tables->table('prices'))->where('id', $id)->update([
            'amount_minor' => $command->amount->amountMinor,
            'compare_at_minor' => $command->compareAt?->amountMinor,
            'updated_at' => PgTimestamp::now(),
        ]);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
