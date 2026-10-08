<?php

declare(strict_types=1);

namespace Commerz\Stores\Internal;

use Commerz\Configuration\CommerzConfig;
use Commerz\Identity\Uuid7;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Schema\TableNames;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\ArchiveStore;
use Commerz\Stores\Data\ChannelView;
use Commerz\Stores\Data\CreateStore;
use Commerz\Stores\Data\ListStores;
use Commerz\Stores\Data\StoreId;
use Commerz\Stores\Data\StoreView;
use Commerz\Stores\Data\UpdateStore;
use Commerz\Stores\Enums\ChannelStatus;
use Commerz\Stores\Enums\StoreStatus;
use Commerz\Stores\Events\ChannelUpdated;
use Commerz\Stores\Events\StoreCreated;
use Commerz\Stores\Exceptions\DuplicateCode;
use Commerz\Stores\Exceptions\StoreNotFound;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

final readonly class DatabaseStores implements Stores
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzConfig $config,
        private TableNames $tables,
        private Transactions $transactions,
        private CommittedEvents $events,
    ) {}

    public function create(CreateStore $command): StoreView
    {
        return $this->transactions->run(function () use ($command): StoreView {
            $db = $this->db();
            $id = Uuid7::generate();
            $now = self::now();

            try {
                // A nested transaction is a savepoint: a unique violation must not poison the caller's transaction.
                $db->transaction(fn () => $db->table($this->tables->table('stores'))->insert([
                    'id' => $id,
                    'code' => $command->code,
                    'name' => $command->name,
                    'default_currency' => $command->defaultCurrency->code,
                    'order_number_prefix' => $command->orderNumberPrefix,
                    'status' => StoreStatus::Active->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateCode('Store', $command->code);
            }

            $view = $this->find(new StoreId($id));
            $this->events->dispatch(new StoreCreated($view));

            return $view;
        });
    }

    public function update(UpdateStore $command): StoreView
    {
        return $this->transactions->run(function () use ($command): StoreView {
            $this->lock($command->store);

            $changes = array_filter([
                'name' => $command->name,
                'order_number_prefix' => $command->orderNumberPrefix,
            ], static fn (?string $value): bool => $value !== null);

            if ($changes !== []) {
                $this->db()->table($this->tables->table('stores'))
                    ->where('id', $command->store->value)
                    ->update($changes + ['updated_at' => self::now()]);
            }

            return $this->find($command->store);
        });
    }

    public function archive(ArchiveStore $command): StoreView
    {
        return $this->transactions->run(function () use ($command): StoreView {
            $store = $this->lock($command->store);

            if ($store->status === StoreStatus::Archived) {
                return $store;
            }

            $now = self::now();
            $db = $this->db();

            $db->table($this->tables->table('stores'))
                ->where('id', $command->store->value)
                ->update(['status' => StoreStatus::Archived->value, 'updated_at' => $now]);

            $channels = $db->table($this->tables->table('channels'))
                ->where('store_id', $command->store->value)
                ->where('status', ChannelStatus::Active->value)
                ->lock('for update')
                ->pluck('id')
                ->all();

            if ($channels !== []) {
                $db->table($this->tables->table('channels'))
                    ->whereIn('id', $channels)
                    ->update(['status' => ChannelStatus::Archived->value, 'updated_at' => $now]);
            }

            foreach ($channels as $channel) {
                if (is_string($channel)) {
                    $this->events->dispatch(new ChannelUpdated($this->channel($channel)));
                }
            }

            return $this->find($command->store);
        });
    }

    public function find(StoreId $id): StoreView
    {
        $row = $this->db()->table($this->tables->table('stores'))->where('id', $id->value)->first();

        return $row === null ? throw new StoreNotFound($id->value) : StoreRows::store($row);
    }

    public function list(ListStores $query): Page
    {
        $builder = $this->db()->table($this->tables->table('stores'))->orderBy('code')->limit($query->first + 1);

        if (! $query->includeArchived) {
            $builder->where('status', StoreStatus::Active->value);
        }

        if ($query->after instanceof Cursor) {
            $builder->where('code', '>', $query->after->position());
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->first;
        $views = array_map(StoreRows::store(...), array_slice($rows, 0, $query->first));

        return new Page($views, $more && $views !== [] ? Cursor::after($views[array_key_last($views)]->code) : null);
    }

    private function lock(StoreId $id): StoreView
    {
        $row = $this->db()->table($this->tables->table('stores'))->where('id', $id->value)->lock('for update')->first();

        return $row === null ? throw new StoreNotFound($id->value) : StoreRows::store($row);
    }

    private function channel(string $id): ChannelView
    {
        $row = $this->db()->table($this->tables->table('channels'))->where('id', $id)->first();

        return StoreRows::channel($row ?? throw new LogicException("Channel {$id} vanished inside the transaction."));
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }

    private static function now(): string
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
