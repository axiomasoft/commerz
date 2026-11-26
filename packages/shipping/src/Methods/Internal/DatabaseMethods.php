<?php

declare(strict_types=1);

namespace Commerz\Shipping\Methods\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\Uuid7;
use Commerz\Measures\LocalizedText;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Shipping\Carriers\Contracts\Carrier;
use Commerz\Shipping\Common\Exceptions\DuplicateCode;
use Commerz\Shipping\Methods\Contracts\Methods;
use Commerz\Shipping\Methods\Data\ArchiveMethod;
use Commerz\Shipping\Methods\Data\AssignMethodChannels;
use Commerz\Shipping\Methods\Data\CreateMethod;
use Commerz\Shipping\Methods\Data\DisableMethod;
use Commerz\Shipping\Methods\Data\EnableMethod;
use Commerz\Shipping\Methods\Data\ListMethods;
use Commerz\Shipping\Methods\Data\MethodId;
use Commerz\Shipping\Methods\Data\MethodView;
use Commerz\Shipping\Methods\Data\SetMethodRates;
use Commerz\Shipping\Methods\Data\UnassignMethodChannels;
use Commerz\Shipping\Methods\Data\UpdateMethod;
use Commerz\Shipping\Methods\Data\ZoneRate;
use Commerz\Shipping\Methods\Enums\MethodStatus;
use Commerz\Shipping\Methods\Events\MethodChanged;
use Commerz\Shipping\Methods\Exceptions\MethodArchived;
use Commerz\Shipping\Methods\Exceptions\MethodNotFound;
use Commerz\Shipping\Rates\Data\RateScope;
use Commerz\Shipping\Rates\Internal\RateCodec;
use Commerz\Shipping\Rates\Internal\StoredRate;
use Commerz\Shipping\Zones\Enums\ZoneStatus;
use Commerz\Shipping\Zones\Exceptions\ZoneArchived;
use Commerz\Shipping\Zones\Exceptions\ZoneNotFound;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Data\ChannelId;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Contributions\Contributions;

/**
 * Lock order of the package: the method row `for update` first, then the zones of the new tariffs `for share` in id
 * order. Archiving, restoring or re-membering a zone takes only that zone `for update`, so it waits for the methods
 * writing a tariff in it and cannot form a cycle with them.
 */
#[Internal]
final readonly class DatabaseMethods implements Methods
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private CommittedEvents $events,
        private Channels $channels,
        private Contributions $contributions,
        private RateCodec $codec,
        private MethodRows $rows,
    ) {}

    public function create(CreateMethod $command): MethodView
    {
        return $this->transactions->run(function () use ($command): MethodView {
            $this->assertCarrier($command->carrier);
            $stored = $this->encode($command->rates);
            $channels = self::unique($command->channels);

            foreach ($channels as $channel) {
                $this->channels->assertActive($channel);
            }

            $db = $this->db();
            $id = Uuid7::generate();
            $now = PgTimestamp::now();

            try {
                // A nested transaction is a savepoint: a unique violation must not poison the caller's transaction.
                $db->transaction(fn () => $db->table($this->tables->table('shipping_methods'))->insert([
                    'id' => $id,
                    'code' => $command->code,
                    'name' => json_encode($command->name->texts, JSON_THROW_ON_ERROR),
                    'carrier' => $command->carrier,
                    'status' => $command->status->value,
                    'position' => $command->position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateCode('Shipping method', $command->code);
            }

            $this->lockZones($command->rates);
            $this->rows->insertChannels($id, $channels);
            $this->rows->insertRates($id, $command->rates, $stored);
            $this->events->dispatch(new MethodChanged(new MethodId($id)));

            return $this->find(new MethodId($id));
        });
    }

    public function update(UpdateMethod $command): MethodView
    {
        return $this->transactions->run(function () use ($command): MethodView {
            $row = $this->lockActive($command->id);
            $changes = [];

            if ($command->name instanceof LocalizedText && ! self::sameTexts($command->name->texts, self::texts($row))) {
                $changes['name'] = json_encode($command->name->texts, JSON_THROW_ON_ERROR);
            }

            if ($command->carrier !== null && $command->carrier !== Rows::string($row, 'carrier')) {
                $this->assertCarrier($command->carrier);
                $changes['carrier'] = $command->carrier;
            }

            if ($command->position !== null && $command->position !== Rows::int($row, 'position')) {
                $changes['position'] = $command->position;
            }

            if ($changes !== []) {
                $this->touch($command->id, $changes);
                $this->events->dispatch(new MethodChanged($command->id));
            }

            return $this->find($command->id);
        });
    }

    public function setRates(SetMethodRates $command): MethodView
    {
        return $this->transactions->run(function () use ($command): MethodView {
            $this->lockActive($command->id);
            $stored = $this->encode($command->rates);
            $this->lockZones($command->rates);

            if (! $this->rows->sameRates($command->id->value, $command->rates, $stored)) {
                $this->db()->table($this->tables->table('shipping_rates'))->where('shipping_method_id', $command->id->value)->delete();
                $this->rows->insertRates($command->id->value, $command->rates, $stored);
                $this->touch($command->id);
                $this->events->dispatch(new MethodChanged($command->id));
            }

            return $this->find($command->id);
        });
    }

    public function assignChannels(AssignMethodChannels $command): MethodView
    {
        return $this->transactions->run(function () use ($command): MethodView {
            $this->lockActive($command->id);
            $channels = self::unique($command->channels);

            foreach ($channels as $channel) {
                $this->channels->assertActive($channel);
            }

            if ($this->rows->insertChannels($command->id->value, $channels) > 0) {
                $this->touch($command->id);
                $this->events->dispatch(new MethodChanged($command->id));
            }

            return $this->find($command->id);
        });
    }

    public function unassignChannels(UnassignMethodChannels $command): MethodView
    {
        return $this->transactions->run(function () use ($command): MethodView {
            $this->lockActive($command->id);

            $removed = $this->db()->table($this->tables->table('shipping_method_channels'))
                ->where('shipping_method_id', $command->id->value)
                ->whereIn('channel_id', array_map(static fn (ChannelId $channel): string => $channel->value, $command->channels))
                ->delete();

            if ($removed > 0) {
                $this->touch($command->id);
                $this->events->dispatch(new MethodChanged($command->id));
            }

            return $this->find($command->id);
        });
    }

    public function enable(EnableMethod $command): MethodView
    {
        return $this->transition($command->id, MethodStatus::Active);
    }

    public function disable(DisableMethod $command): MethodView
    {
        return $this->transition($command->id, MethodStatus::Disabled);
    }

    public function archive(ArchiveMethod $command): MethodView
    {
        return $this->transition($command->id, MethodStatus::Archived);
    }

    public function find(MethodId $id): MethodView
    {
        $row = $this->db()->table($this->tables->table('shipping_methods'))->where('id', $id->value)->first();

        return $this->rows->views([$row ?? throw new MethodNotFound($id->value)])[0];
    }

    public function findByCode(string $code): MethodView
    {
        $row = $this->db()->table($this->tables->table('shipping_methods'))->where('code', $code)->first();

        return $this->rows->views([$row ?? throw new MethodNotFound($code)])[0];
    }

    public function list(ListMethods $query): Page
    {
        $db = $this->db();
        $builder = $db->table($this->tables->table('shipping_methods'))
            ->orderBy('position')
            ->orderBy('code')
            ->orderBy('id')
            ->limit($query->limit + 1);

        if ($query->status instanceof MethodStatus) {
            $builder->where('status', $query->status->value);
        }

        if ($query->channel instanceof ChannelId) {
            $builder->whereIn('id', $db->table($this->tables->table('shipping_method_channels'))
                ->select('shipping_method_id')
                ->where('channel_id', $query->channel->value));
        }

        if ($query->zone !== null) {
            $builder->whereIn('id', $db->table($this->tables->table('shipping_rates'))
                ->select('shipping_method_id')
                ->where('shipping_zone_id', $query->zone->value));
        }

        if ($query->after instanceof Cursor) {
            self::after($builder, $query->after);
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->limit;
        $views = $this->rows->views(array_slice($rows, 0, $query->limit));

        return new Page($views, $more && $views !== [] ? Cursor::after($views[array_key_last($views)]->position.':'.$views[array_key_last($views)]->code) : null);
    }

    /** Moves to `$target`; already there, or archived and archiving, is no change. */
    private function transition(MethodId $id, MethodStatus $target): MethodView
    {
        return $this->transactions->run(function () use ($id, $target): MethodView {
            $row = $this->lock($id);
            $current = MethodStatus::from(Rows::string($row, 'status'));

            if ($current === $target) {
                return $this->find($id);
            }

            if ($current === MethodStatus::Archived) {
                throw new MethodArchived(Rows::string($row, 'code'));
            }

            $this->touch($id, ['status' => $target->value]);
            $this->events->dispatch(new MethodChanged($id));

            return $this->find($id);
        });
    }

    /** The keyset is (position, code); the code is unique and has no colon. */
    private static function after(Builder $builder, Cursor $cursor): void
    {
        $parts = explode(':', $cursor->position(), 2);

        if (count($parts) !== 2 || preg_match('/\A-?\d{1,10}\z/', $parts[0]) !== 1) {
            throw new InvalidCommand('after', 'is not a valid cursor', 'common.invalid_cursor');
        }

        [$position, $code] = [(int) $parts[0], $parts[1]];

        $builder->where(static function (Builder $where) use ($position, $code): void {
            $where->where('position', '>', $position)
                ->orWhere(static fn (Builder $tie): Builder => $tie->where('position', $position)->where('code', '>', $code));
        });
    }

    /**
     * @param  list<ZoneRate>  $rates
     * @return list<StoredRate> the encoded rule of each rate, in order
     *
     * @throws InvalidCommand shipping.unknown_rate, shipping.invalid_rate_config
     */
    private function encode(array $rates): array
    {
        $stored = [];

        foreach ($rates as $index => $rate) {
            $stored[] = $this->codec->encode($rate->rate, new RateScope($rate->currency), "rates.{$index}.rate");
        }

        return $stored;
    }

    /**
     * Takes the zones of the tariffs `for share` in id order, so none is archived before this transaction ends.
     *
     * @param  list<ZoneRate>  $rates
     *
     * @throws ZoneNotFound|ZoneArchived
     */
    private function lockZones(array $rates): void
    {
        $ids = array_values(array_unique(array_map(static fn (ZoneRate $rate): string => $rate->zone->value, $rates)));
        sort($ids, SORT_STRING);

        if ($ids === []) {
            return;
        }

        $zones = [];

        foreach ($this->db()->table($this->tables->table('shipping_zones'))->whereIn('id', $ids)->orderBy('id')->sharedLock()->get() as $row) {
            $zones[Rows::string($row, 'id')] = $row;
        }

        foreach ($ids as $id) {
            $zone = $zones[$id] ?? throw new ZoneNotFound($id);

            if (Rows::string($zone, 'status') === ZoneStatus::Archived->value) {
                throw new ZoneArchived(Rows::string($zone, 'code'));
            }
        }
    }

    private function assertCarrier(string $carrier): void
    {
        if (! $this->contributions->has(Carrier::KIND, $carrier)) {
            throw new InvalidCommand('carrier', "no carrier contributes '{$carrier}'", 'shipping.unknown_carrier');
        }
    }

    /** @param  array<string, mixed>  $changes */
    private function touch(MethodId $id, array $changes = []): void
    {
        $this->db()->table($this->tables->table('shipping_methods'))
            ->where('id', $id->value)
            ->update($changes + ['updated_at' => PgTimestamp::now()]);
    }

    private function lock(MethodId $id): object
    {
        $row = $this->db()->table($this->tables->table('shipping_methods'))
            ->where('id', $id->value)
            ->lockForUpdate()
            ->first();

        return $row ?? throw new MethodNotFound($id->value);
    }

    private function lockActive(MethodId $id): object
    {
        $row = $this->lock($id);

        if (Rows::string($row, 'status') === MethodStatus::Archived->value) {
            throw new MethodArchived(Rows::string($row, 'code'));
        }

        return $row;
    }

    /**
     * @param  array<array-key, string>  $a
     * @param  array<array-key, string>  $b
     */
    private static function sameTexts(array $a, array $b): bool
    {
        // jsonb does not keep the order of keys.
        ksort($a);
        ksort($b);

        return $a === $b;
    }

    /** @return array<array-key, string> */
    private static function texts(object $row): array
    {
        return Rows::strings($row, 'name');
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
