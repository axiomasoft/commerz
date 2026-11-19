<?php

declare(strict_types=1);

namespace Commerz\Inventory\Transfers\Internal;

use Closure;
use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\IdempotencyKey;
use Commerz\Identity\Uuid7;
use Commerz\Inventory\Common\Internal\Clock;
use Commerz\Inventory\Common\Internal\LevelRow;
use Commerz\Inventory\Common\Internal\Rows;
use Commerz\Inventory\Common\Internal\StockLedger;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Items\Enums\StockItemStatus;
use Commerz\Inventory\Items\Enums\StockPolicy;
use Commerz\Inventory\Items\Exceptions\StockItemArchived;
use Commerz\Inventory\Items\Exceptions\UntrackedStockItem;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Inventory\Locations\Enums\LocationStatus;
use Commerz\Inventory\Locations\Exceptions\LocationArchived;
use Commerz\Inventory\Reservations\Internal\DatabaseReservations;
use Commerz\Inventory\Stock\Enums\MovementKind;
use Commerz\Inventory\Stock\Internal\StockEntities;
use Commerz\Inventory\Transfers\Contracts\Transfers;
use Commerz\Inventory\Transfers\Data\CancelTransfer;
use Commerz\Inventory\Transfers\Data\CreateTransfer;
use Commerz\Inventory\Transfers\Data\DispatchTransfer;
use Commerz\Inventory\Transfers\Data\ListTransfers;
use Commerz\Inventory\Transfers\Data\ReceiveTransfer;
use Commerz\Inventory\Transfers\Data\TransferId;
use Commerz\Inventory\Transfers\Data\TransferLine;
use Commerz\Inventory\Transfers\Data\TransferLineView;
use Commerz\Inventory\Transfers\Data\TransferReceipt;
use Commerz\Inventory\Transfers\Data\TransferView;
use Commerz\Inventory\Transfers\Enums\TransferState;
use Commerz\Inventory\Transfers\Events\TransferUpdated;
use Commerz\Inventory\Transfers\Exceptions\InvalidTransferState;
use Commerz\Inventory\Transfers\Exceptions\TransferNotFound;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\OperationReceipts;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Operations\Fingerprint;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Schema\TableNames;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;
use RuntimeException;

/**
 * Every command starts by taking the transfer row `for no key update` and checking the state under it (INV-06), then
 * follows the inventory lock order: the items and the locations `for share`, the reservation header of the transfer,
 * the levels in one ordered statement, the reservation lines. The stock of a draft is a reservation of demand
 * `transfer:{id}` at the source; goods in transit sit on no level, only on the transfer's lines.
 */
#[Internal]
final readonly class DatabaseTransfers implements Transfers
{
    private const string DATETIME = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private OperationReceipts $receipts,
        private CommittedEvents $events,
        private StockLedger $ledger,
        private StockEntities $entities,
        private DatabaseReservations $reservations,
    ) {}

    public function create(CreateTransfer $command): TransferView
    {
        $lines = array_map(static fn (TransferLine $line): array => [$line->item->value, $line->quantity], $command->lines);
        sort($lines);

        return $this->keyed('inventory.transfer_create', $command->idempotencyKey, [
            'from' => $command->from->value,
            'to' => $command->to->value,
            'lines' => $lines,
            'reference' => $command->reference,
        ], fn (): TransferView => $this->atomically(fn (): TransferView => $this->place($command)));
    }

    public function dispatch(DispatchTransfer $command): TransferView
    {
        return $this->atomically(function () use ($command): TransferView {
            $id = $command->transfer->value;
            $this->assertState($this->lock($command->transfer), TransferState::Draft, 'dispatched');

            $demand = self::demand($id);
            $this->reservations->shipTransfer($demand, $demand);

            $now = Clock::now();
            $this->db()->update(
                "update {$this->transferTable()} set state = ?, dispatched_at = ?, updated_at = ? where id = ?",
                [TransferState::InTransit->value, $now, $now, $id],
            );

            return $this->announce($id);
        });
    }

    public function receive(ReceiveTransfer $command): TransferView
    {
        $lines = array_map(static fn (TransferReceipt $line): array => [$line->item->value, $line->received, $line->lost], $command->lines);
        sort($lines);

        return $this->keyed('inventory.transfer_receive', $command->idempotencyKey, [
            'transfer' => $command->transfer->value,
            'lines' => $lines,
        ], fn (): TransferView => $this->atomically(fn (): TransferView => $this->accept($command)));
    }

    public function cancel(CancelTransfer $command): TransferView
    {
        return $this->atomically(function () use ($command): TransferView {
            $id = $command->transfer->value;
            $row = $this->lock($command->transfer);

            if (Rows::string($row, 'state') === TransferState::Cancelled->value) {
                return $this->read($id) ?? throw new TransferNotFound($id);
            }

            $this->assertState($row, TransferState::Draft, 'cancelled');
            $this->reservations->releaseTransfer(self::demand($id));
            $this->db()->update(
                "update {$this->transferTable()} set state = ?, updated_at = ? where id = ?",
                [TransferState::Cancelled->value, Clock::now(), $id],
            );

            return $this->announce($id);
        });
    }

    public function find(TransferId $id): TransferView
    {
        return $this->read($id->value) ?? throw new TransferNotFound($id->value);
    }

    public function list(ListTransfers $query): Page
    {
        $where = [];
        $bindings = [];

        if ($query->state instanceof TransferState) {
            $where[] = 't.state = ?';
            $bindings[] = $query->state->value;
        }

        if ($query->location instanceof LocationId) {
            $where[] = '(t.from_location_id = ? or t.to_location_id = ?)';
            $bindings[] = $query->location->value;
            $bindings[] = $query->location->value;
        }

        if ($query->after instanceof Cursor) {
            $position = strtolower($query->after->position());

            if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $position) !== 1) {
                throw new InvalidCommand('after', 'is not a valid cursor', 'common.invalid_cursor');
            }

            $where[] = 't.id > ?::uuid';
            $bindings[] = $position;
        }

        // One statement: the page and its lines come from one snapshot.
        $rows = $this->db()->select(
            "with page as (select t.* from {$this->transferTable()} t"
            .($where === [] ? '' : ' where '.implode(' and ', $where))
            .' order by t.id limit '.($query->first + 1).')'
            .' '.$this->selectView('page'),
            $bindings,
            useReadPdo: $this->db()->transactionLevel() === 0,
        );

        $views = self::hydrate($rows);
        $more = count($views) > $query->first;
        $views = array_slice($views, 0, $query->first);

        return new Page($views, $more && $views !== [] ? Cursor::after($views[array_key_last($views)]->id->value) : null);
    }

    private function place(CreateTransfer $command): TransferView
    {
        $quantities = [];

        foreach ($command->lines as $line) {
            $quantities[$line->item->value] = $line->quantity;
        }

        ksort($quantities, SORT_STRING);

        foreach ($this->entities->items(array_keys($quantities)) as $item) {
            $sku = Rows::string($item, 'sku');

            if (Rows::string($item, 'policy') === StockPolicy::Untracked->value) {
                throw UntrackedStockItem::keepsNoLevels($sku);
            }

            if (Rows::string($item, 'status') === StockItemStatus::Archived->value) {
                throw new StockItemArchived($sku);
            }
        }

        $locations = [$command->from->value, $command->to->value];
        sort($locations, SORT_STRING);

        foreach ($locations as $location) {
            $row = $this->entities->location($location);

            if (Rows::string($row, 'status') === LocationStatus::Archived->value) {
                throw new LocationArchived(Rows::string($row, 'code'));
            }
        }

        $id = Uuid7::generate();
        $now = Clock::now();
        $db = $this->db();

        $db->table($this->transferTable())->insert([
            'id' => $id,
            'from_location_id' => $command->from->value,
            'to_location_id' => $command->to->value,
            'state' => TransferState::Draft->value,
            'reference' => $command->reference,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $rows = [];

        foreach ($quantities as $item => $quantity) {
            $rows[] = [
                'id' => Uuid7::generate(),
                'transfer_id' => $id,
                'stock_item_id' => $item,
                'quantity' => $quantity,
                'position' => count($rows) + 1,
            ];
        }

        $db->table($this->lineTable())->insert($rows);
        $this->reservations->holdTransfer(self::demand($id), $command->from->value, $quantities);

        return $this->announce($id);
    }

    private function accept(ReceiveTransfer $command): TransferView
    {
        $id = $command->transfer->value;
        $row = $this->lock($command->transfer);
        $this->assertState($row, TransferState::InTransit, 'received');

        $lines = [];

        foreach ($this->db()->table($this->lineTable())->where('transfer_id', $id)->orderBy('stock_item_id')->get() as $line) {
            $lines[Rows::string($line, 'stock_item_id')] = $line;
        }

        $receipts = [];

        foreach ($command->lines as $receipt) {
            $item = $receipt->item->value;
            $line = $lines[$item] ?? throw new InvalidCommand('lines', "stock item '{$item}' is not on the transfer", 'inventory.invalid_transfer');
            $inTransit = Rows::int($line, 'quantity') - Rows::int($line, 'received') - Rows::int($line, 'lost');

            if ($receipt->lost > $inTransit || $receipt->received > $inTransit - $receipt->lost) {
                throw new InvalidCommand('lines', "stock item '{$item}': received and lost exceed the {$inTransit} units in transit", 'inventory.invalid_transfer');
            }

            $receipts[$item] = $receipt;
        }

        ksort($receipts, SORT_STRING);
        $arriving = array_filter($receipts, static fn (TransferReceipt $receipt): bool => $receipt->received > 0);

        if ($arriving !== []) {
            $this->putOnHand($row, $arriving);
        }

        $settled = true;

        foreach ($lines as $item => $line) {
            $received = Rows::int($line, 'received');
            $lost = Rows::int($line, 'lost');

            if (isset($receipts[$item])) {
                $received += $receipts[$item]->received;
                $lost += $receipts[$item]->lost;
                $this->db()->update(
                    "update {$this->lineTable()} set received = ?, lost = ? where id = ?",
                    [$received, $lost, Rows::string($line, 'id')],
                );
            }

            $settled = $settled && $received + $lost === Rows::int($line, 'quantity');
        }

        $now = Clock::now();
        $this->db()->update(
            "update {$this->transferTable()} set state = ?, completed_at = ?, updated_at = ? where id = ?",
            [$settled ? TransferState::Completed->value : TransferState::InTransit->value, $settled ? $now : null, $now, $id],
        );

        return $this->announce($id);
    }

    /**
     * Puts the received units on hand at the destination (movement `transfer_in`).
     *
     * @param  array<string, TransferReceipt>  $arriving  by stock item id, in lock order
     */
    private function putOnHand(object $transfer, array $arriving): void
    {
        foreach ($this->entities->items(array_keys($arriving)) as $item) {
            $sku = Rows::string($item, 'sku');

            if (Rows::string($item, 'policy') === StockPolicy::Untracked->value) {
                throw UntrackedStockItem::keepsNoLevels($sku);
            }

            if (Rows::string($item, 'status') === StockItemStatus::Archived->value) {
                throw new StockItemArchived($sku);
            }
        }

        $to = Rows::string($transfer, 'to_location_id');
        $location = $this->entities->location($to);

        if (Rows::string($location, 'status') === LocationStatus::Archived->value) {
            throw new LocationArchived(Rows::string($location, 'code'));
        }

        $pairs = array_map(static fn (string $item): array => [$item, $to], array_keys($arriving));
        $this->ledger->ensureLevels($pairs);
        $levels = $this->ledger->lock($pairs);
        $reference = self::demand(Rows::string($transfer, 'id'));

        foreach ($arriving as $item => $receipt) {
            $level = $levels[LevelRow::key($item, $to)]
                ?? throw new RuntimeException("The level of {$item} at {$to} vanished under its locks.");

            $this->ledger->move($level, $receipt->received, MovementKind::TransferIn, $reference);
        }
    }

    /** Reads the transfer as the command left it and queues {@see TransferUpdated} for after the commit. */
    private function announce(string $id): TransferView
    {
        $view = $this->read($id) ?? throw new RuntimeException("Transfer {$id} vanished.");
        $this->events->dispatch(new TransferUpdated($view));

        return $view;
    }

    /**
     * @param  Closure(): TransferView  $work
     */
    private function atomically(Closure $work): TransferView
    {
        return $this->transactions->run(fn (): TransferView => $this->ledger->within($work));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  Closure(): TransferView  $work
     */
    private function keyed(string $scope, ?IdempotencyKey $key, array $payload, Closure $work): TransferView
    {
        if (! $key instanceof IdempotencyKey) {
            return $work();
        }

        return $this->receipts->once($scope, $key, Fingerprint::of($payload), $work, self::encode(...), self::decode(...));
    }

    private function lock(TransferId $id): object
    {
        $row = $this->db()->table($this->transferTable())
            ->where('id', $id->value)
            ->lock('for no key update')
            ->first();

        return $row ?? throw new TransferNotFound($id->value);
    }

    private function assertState(object $row, TransferState $required, string $action): void
    {
        $state = TransferState::from(Rows::string($row, 'state'));

        if ($state !== $required) {
            throw new InvalidTransferState(Rows::string($row, 'id'), $state, $action);
        }
    }

    private function read(string $id): ?TransferView
    {
        $rows = $this->db()->select(
            "with page as (select t.* from {$this->transferTable()} t where t.id = ?) ".$this->selectView('page'),
            [$id],
            useReadPdo: $this->db()->transactionLevel() === 0,
        );

        return self::hydrate($rows)[0] ?? null;
    }

    /** The transfers of the CTE `$page` with their locations and lines, one row per line. */
    private function selectView(string $page): string
    {
        return 'select t.id, t.from_location_id, fl.code as from_code, t.to_location_id, tl.code as to_code, t.state, t.reference,'
            .' t.dispatched_at, t.completed_at, ln.stock_item_id, i.sku, ln.quantity, ln.received, ln.lost'
            ." from {$page} t"
            ." join {$this->tables->table('stock_locations')} fl on fl.id = t.from_location_id"
            ." join {$this->tables->table('stock_locations')} tl on tl.id = t.to_location_id"
            ." left join {$this->lineTable()} ln on ln.transfer_id = t.id"
            ." left join {$this->tables->table('stock_items')} i on i.id = ln.stock_item_id"
            .' order by t.id, i.sku, ln.id';
    }

    /**
     * @param  array<array-key, mixed>  $rows  of {@see selectView()}
     * @return list<TransferView>
     */
    private static function hydrate(array $rows): array
    {
        $views = [];

        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }

            $id = Rows::string($row, 'id');
            $state = TransferState::from(Rows::string($row, 'state'));

            if (! isset($views[$id])) {
                $dispatched = Rows::nullableString($row, 'dispatched_at');
                $completed = Rows::nullableString($row, 'completed_at');
                $views[$id] = [
                    'id' => $id,
                    'from' => Rows::string($row, 'from_location_id'),
                    'from_code' => Rows::string($row, 'from_code'),
                    'to' => Rows::string($row, 'to_location_id'),
                    'to_code' => Rows::string($row, 'to_code'),
                    'state' => $state->value,
                    'reference' => Rows::nullableString($row, 'reference'),
                    'dispatched_at' => $dispatched === null ? null : new DateTimeImmutable($dispatched)->format(self::DATETIME),
                    'completed_at' => $completed === null ? null : new DateTimeImmutable($completed)->format(self::DATETIME),
                    'lines' => [],
                ];
            }

            if (Rows::nullableString($row, 'stock_item_id') !== null) {
                $views[$id]['lines'][] = [
                    'item' => Rows::string($row, 'stock_item_id'),
                    'sku' => Rows::string($row, 'sku'),
                    'quantity' => Rows::int($row, 'quantity'),
                    'received' => Rows::int($row, 'received'),
                    'lost' => Rows::int($row, 'lost'),
                ];
            }
        }

        return array_values(array_map(self::decode(...), $views));
    }

    /** @return array<string, mixed> */
    private static function encode(TransferView $view): array
    {
        return [
            'id' => $view->id->value,
            'from' => $view->from->value,
            'from_code' => $view->fromCode,
            'to' => $view->to->value,
            'to_code' => $view->toCode,
            'state' => $view->state->value,
            'reference' => $view->reference,
            'dispatched_at' => $view->dispatchedAt?->format(self::DATETIME),
            'completed_at' => $view->completedAt?->format(self::DATETIME),
            'lines' => array_map(static fn (TransferLineView $line): array => [
                'item' => $line->item->value,
                'sku' => $line->sku,
                'quantity' => $line->quantity,
                'received' => $line->received,
                'lost' => $line->lost,
            ], $view->lines),
        ];
    }

    /** @param  array<array-key, mixed>  $stored */
    private static function decode(array $stored): TransferView
    {
        $string = static fn (array $from, string $key): string => is_string($from[$key] ?? null) ? $from[$key] : throw new RuntimeException("Stored transfer lacks '{$key}'.");
        $date = static fn (string $key): ?DateTimeImmutable => is_string($stored[$key] ?? null) ? new DateTimeImmutable($stored[$key]) : null;
        $state = TransferState::from($string($stored, 'state'));
        $lines = [];

        foreach (is_array($stored['lines'] ?? null) ? $stored['lines'] : [] as $line) {
            if (! is_array($line)) {
                throw new RuntimeException('Stored transfer has a malformed line.');
            }

            $int = static fn (string $key): int => is_int($line[$key] ?? null) ? $line[$key] : throw new RuntimeException("Stored transfer line lacks '{$key}'.");
            $lines[] = new TransferLineView(
                new StockItemId($string($line, 'item')),
                $string($line, 'sku'),
                $int('quantity'),
                $int('received'),
                $int('lost'),
                $state === TransferState::InTransit ? $int('quantity') - $int('received') - $int('lost') : 0,
            );
        }

        return new TransferView(
            new TransferId($string($stored, 'id')),
            new LocationId($string($stored, 'from')),
            $string($stored, 'from_code'),
            new LocationId($string($stored, 'to')),
            $string($stored, 'to_code'),
            $state,
            is_string($stored['reference'] ?? null) ? $stored['reference'] : null,
            $lines,
            $date('dispatched_at'),
            $date('completed_at'),
        );
    }

    /** The reservation demand and the movement reference of a transfer. */
    private static function demand(string $id): string
    {
        return 'transfer:'.$id;
    }

    private function transferTable(): string
    {
        return $this->tables->table('stock_transfers');
    }

    private function lineTable(): string
    {
        return $this->tables->table('stock_transfer_lines');
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
