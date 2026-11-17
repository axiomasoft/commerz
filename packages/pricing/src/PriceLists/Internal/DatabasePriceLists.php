<?php

declare(strict_types=1);

namespace Commerz\Pricing\PriceLists\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Identity\Uuid7;
use Commerz\Money\Currency;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Pricing\Common\Exceptions\DuplicateCode;
use Commerz\Pricing\Common\Internal\Clock;
use Commerz\Pricing\Common\Internal\Rows;
use Commerz\Pricing\PriceLists\Contracts\PriceLists;
use Commerz\Pricing\PriceLists\Data\ArchivePriceList;
use Commerz\Pricing\PriceLists\Data\AssignPriceListChannels;
use Commerz\Pricing\PriceLists\Data\CreatePriceList;
use Commerz\Pricing\PriceLists\Data\ListPriceLists;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use Commerz\Pricing\PriceLists\Data\PriceListView;
use Commerz\Pricing\PriceLists\Data\RestorePriceList;
use Commerz\Pricing\PriceLists\Data\UnassignPriceListChannels;
use Commerz\Pricing\PriceLists\Data\UpdatePriceList;
use Commerz\Pricing\PriceLists\Enums\PriceListStatus;
use Commerz\Pricing\PriceLists\Enums\TaxInclusion;
use Commerz\Pricing\PriceLists\Events\PriceListChanged;
use Commerz\Pricing\PriceLists\Exceptions\PriceListArchived;
use Commerz\Pricing\PriceLists\Exceptions\PriceListNotFound;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Data\ChannelId;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Packman\Attributes\Internal;
use Packman\Schema\Data\Compiler\TableNames;

/**
 * Lock order: the price list row first (`for no key update` to change or archive it, `for share` to put prices or
 * channels in it), then its prices or channel rows. A price cannot enter a list that is being archived.
 */
#[Internal]
final readonly class DatabasePriceLists implements PriceLists
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private CommittedEvents $events,
        private Channels $channels,
    ) {}

    public function create(CreatePriceList $command): PriceListView
    {
        return $this->transactions->run(function () use ($command): PriceListView {
            $db = $this->db();
            $channels = self::unique($command->channels);

            foreach ($channels as $channel) {
                $this->channels->assertActive($channel, $command->currency);
            }

            $id = Uuid7::generate();
            $now = Clock::now();

            try {
                // A nested transaction is a savepoint: a unique violation must not poison the caller's transaction.
                $db->transaction(fn () => $db->table($this->tables->table('price_lists'))->insert([
                    'id' => $id,
                    'code' => $command->code,
                    'name' => $command->name,
                    'currency' => $command->currency->code,
                    'priority' => $command->priority,
                    'tax_included' => $command->taxInclusion->toColumn(),
                    'status' => PriceListStatus::Active->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateCode('Price list', $command->code);
            }

            $this->insertChannels($id, $channels);
            $this->events->dispatch(new PriceListChanged(new PriceListId($id)));

            return $this->find(new PriceListId($id));
        });
    }

    public function update(UpdatePriceList $command): PriceListView
    {
        return $this->transactions->run(function () use ($command): PriceListView {
            $row = $this->lockActive($command->priceList, 'for no key update');
            $changes = [];

            if ($command->name !== null && $command->name !== Rows::string($row, 'name')) {
                $changes['name'] = $command->name;
            }

            if ($command->priority !== null && $command->priority !== Rows::int($row, 'priority')) {
                $changes['priority'] = $command->priority;
            }

            if ($command->taxInclusion instanceof TaxInclusion
                && $command->taxInclusion !== TaxInclusion::fromColumn(Rows::nullableBool($row, 'tax_included'))) {
                $changes['tax_included'] = $command->taxInclusion->toColumn();
            }

            if ($changes !== []) {
                $this->touch($command->priceList, $changes);
                $this->events->dispatch(new PriceListChanged($command->priceList));
            }

            return $this->find($command->priceList);
        });
    }

    public function assignChannels(AssignPriceListChannels $command): PriceListView
    {
        return $this->transactions->run(function () use ($command): PriceListView {
            $row = $this->lockActive($command->priceList, 'for share');
            $currency = Currency::of(Rows::string($row, 'currency'));
            $channels = self::unique($command->channels);

            foreach ($channels as $channel) {
                $this->channels->assertActive($channel, $currency);
            }

            if ($this->insertChannels($command->priceList->value, $channels) > 0) {
                $this->events->dispatch(new PriceListChanged($command->priceList));
            }

            return $this->find($command->priceList);
        });
    }

    public function unassignChannels(UnassignPriceListChannels $command): PriceListView
    {
        return $this->transactions->run(function () use ($command): PriceListView {
            $this->lockActive($command->priceList, 'for share');

            $removed = $this->db()->table($this->tables->table('price_list_channels'))
                ->where('price_list_id', $command->priceList->value)
                ->whereIn('channel_id', array_map(static fn (ChannelId $channel): string => $channel->value, $command->channels))
                ->delete();

            if ($removed > 0) {
                $this->events->dispatch(new PriceListChanged($command->priceList));
            }

            return $this->find($command->priceList);
        });
    }

    public function archive(ArchivePriceList $command): PriceListView
    {
        return $this->transactions->run(function () use ($command): PriceListView {
            $row = $this->lock($command->priceList, 'for no key update');

            if (Rows::string($row, 'status') === PriceListStatus::Active->value) {
                $this->touch($command->priceList, ['status' => PriceListStatus::Archived->value]);
                $this->events->dispatch(new PriceListChanged($command->priceList));
            }

            return $this->find($command->priceList);
        });
    }

    public function restore(RestorePriceList $command): PriceListView
    {
        return $this->transactions->run(function () use ($command): PriceListView {
            $row = $this->lock($command->priceList, 'for no key update');

            if (Rows::string($row, 'status') === PriceListStatus::Archived->value) {
                $this->touch($command->priceList, ['status' => PriceListStatus::Active->value]);
                $this->events->dispatch(new PriceListChanged($command->priceList));
            }

            return $this->find($command->priceList);
        });
    }

    public function find(PriceListId $id): PriceListView
    {
        $row = $this->db()->table($this->tables->table('price_lists'))->where('id', $id->value)->first();

        return $this->view($row ?? throw new PriceListNotFound($id->value), $this->channelsOf([$id->value]));
    }

    public function list(ListPriceLists $query): Page
    {
        $table = $this->tables->table('price_lists');
        $builder = $this->db()->table($table)->orderBy('code')->limit($query->first + 1);

        if ($query->status instanceof PriceListStatus) {
            $builder->where('status', $query->status->value);
        }

        if ($query->currency instanceof Currency) {
            $builder->where('currency', $query->currency->code);
        }

        if ($query->channel instanceof ChannelId) {
            $builder->whereIn('id', $this->db()->table($this->tables->table('price_list_channels'))
                ->select('price_list_id')
                ->where('channel_id', $query->channel->value));
        }

        if ($query->after instanceof Cursor) {
            $builder->where('code', '>', $query->after->position());
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->first;
        $rows = array_slice($rows, 0, $query->first);
        $channels = $this->channelsOf(array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows));
        $views = array_map(fn (object $row): PriceListView => $this->view($row, $channels), $rows);

        return new Page($views, $more && $views !== [] ? Cursor::after($views[array_key_last($views)]->code) : null);
    }

    /** @param  list<ChannelId>  $channels */
    private function insertChannels(string $priceList, array $channels): int
    {
        if ($channels === []) {
            return 0;
        }

        $now = Clock::now();

        return $this->db()->table($this->tables->table('price_list_channels'))->insertOrIgnore(array_map(
            static fn (ChannelId $channel): array => [
                'price_list_id' => $priceList,
                'channel_id' => $channel->value,
                'created_at' => $now,
            ],
            $channels,
        ));
    }

    /** @param  array<string, mixed>  $changes */
    private function touch(PriceListId $id, array $changes): void
    {
        $this->db()->table($this->tables->table('price_lists'))
            ->where('id', $id->value)
            ->update($changes + ['updated_at' => Clock::now()]);
    }

    private function lock(PriceListId $id, string $mode): object
    {
        $row = $this->db()->table($this->tables->table('price_lists'))
            ->where('id', $id->value)
            ->lock($mode)
            ->first();

        return $row ?? throw new PriceListNotFound($id->value);
    }

    private function lockActive(PriceListId $id, string $mode): object
    {
        $row = $this->lock($id, $mode);

        if (Rows::string($row, 'status') === PriceListStatus::Archived->value) {
            throw new PriceListArchived(Rows::string($row, 'code'));
        }

        return $row;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, list<ChannelId>> channels by price list id, sorted by channel id
     */
    private function channelsOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->db()->table($this->tables->table('price_list_channels'))
            ->whereIn('price_list_id', $ids)
            ->orderBy('channel_id')
            ->get(['price_list_id', 'channel_id'])
            ->all();

        $byList = [];

        foreach ($rows as $row) {
            $byList[Rows::string($row, 'price_list_id')][] = new ChannelId(Rows::string($row, 'channel_id'));
        }

        return $byList;
    }

    /** @param  array<string, list<ChannelId>>  $channels */
    private function view(object $row, array $channels): PriceListView
    {
        $id = Rows::string($row, 'id');

        return new PriceListView(
            new PriceListId($id),
            Rows::string($row, 'code'),
            Rows::string($row, 'name'),
            Currency::of(Rows::string($row, 'currency')),
            Rows::int($row, 'priority'),
            TaxInclusion::fromColumn(Rows::nullableBool($row, 'tax_included')),
            PriceListStatus::from(Rows::string($row, 'status')),
            $channels[$id] ?? [],
            Rows::date($row, 'created_at'),
            Rows::date($row, 'updated_at'),
        );
    }

    /**
     * @param  list<ChannelId>  $channels
     * @return list<ChannelId> without repeats, in input order
     */
    private static function unique(array $channels): array
    {
        $unique = [];

        foreach ($channels as $channel) {
            $unique[$channel->value] ??= $channel;
        }

        return array_values($unique);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
