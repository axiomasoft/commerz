<?php

declare(strict_types=1);

namespace Commerz\Inventory\Items\Internal;

use Commerz\Configuration\CommerzConfig;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Identity\Uuid7;
use Commerz\Inventory\Common\Exceptions\DuplicateCode;
use Commerz\Inventory\Common\Internal\Clock;
use Commerz\Inventory\Common\Internal\LevelQueries;
use Commerz\Inventory\Common\Internal\Rows;
use Commerz\Inventory\Items\Contracts\StockItems;
use Commerz\Inventory\Items\Data\ArchiveStockItem;
use Commerz\Inventory\Items\Data\CreateStockItem;
use Commerz\Inventory\Items\Data\LinkSellable;
use Commerz\Inventory\Items\Data\ListLinks;
use Commerz\Inventory\Items\Data\ListStockItems;
use Commerz\Inventory\Items\Data\RestoreStockItem;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Items\Data\StockItemView;
use Commerz\Inventory\Items\Data\StockLinkView;
use Commerz\Inventory\Items\Data\UnlinkSellable;
use Commerz\Inventory\Items\Data\UpdateStockItem;
use Commerz\Inventory\Items\Enums\StockItemStatus;
use Commerz\Inventory\Items\Enums\StockPolicy;
use Commerz\Inventory\Items\Exceptions\StockItemArchived;
use Commerz\Inventory\Items\Exceptions\StockItemNotEmpty;
use Commerz\Inventory\Items\Exceptions\StockItemNotFound;
use Commerz\Inventory\Items\Exceptions\TooManyComponents;
use Commerz\Inventory\Items\Exceptions\UntrackedStockItem;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Lock order: the item row, then (archive, untrack) its levels. A link holds the item `for share`, so archiving it
 * (`for no key update`) waits for the link and the other way round; the 20-component limit is checked under an
 * advisory lock of the sellable, so two parallel links cannot both take the last place.
 */
final readonly class DatabaseStockItems implements StockItems
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzConfig $config,
        private TableNames $tables,
        private Transactions $transactions,
        private LevelQueries $levels,
    ) {}

    public function create(CreateStockItem $command): StockItemView
    {
        return $this->transactions->run(function () use ($command): StockItemView {
            $db = $this->db();
            $id = Uuid7::generate();
            $now = Clock::now();

            try {
                // A nested transaction is a savepoint: a unique violation must not poison the caller's transaction.
                $db->transaction(fn () => $db->table($this->tables->table('stock_items'))->insert([
                    'id' => $id,
                    'sku' => $command->sku,
                    'name' => $command->name,
                    'unit' => $command->unit,
                    'policy' => $command->policy->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateCode('Stock item', $command->sku);
            }

            return $this->find(new StockItemId($id));
        });
    }

    public function update(UpdateStockItem $command): StockItemView
    {
        return $this->transactions->run(function () use ($command): StockItemView {
            $row = $this->lockActive($command->item, 'for no key update');
            $changes = [];

            if ($command->name !== null && $command->name !== Rows::nullableString($row, 'name')) {
                $changes['name'] = $command->name;
            }

            if ($command->policy instanceof StockPolicy && $command->policy->value !== Rows::string($row, 'policy')) {
                if ($command->policy === StockPolicy::Untracked && ($this->hasOpenTransfer($command->item) || $this->levels->lockedItemNonEmpty($command->item->value))) {
                    throw new UntrackedStockItem(Rows::string($row, 'sku'));
                }

                $changes['policy'] = $command->policy->value;
            }

            if ($changes !== []) {
                $this->db()->table($this->tables->table('stock_items'))
                    ->where('id', $command->item->value)
                    ->update($changes + ['updated_at' => Clock::now()]);
            }

            return $this->find($command->item);
        });
    }

    public function archive(ArchiveStockItem $command): StockItemView
    {
        return $this->transactions->run(function () use ($command): StockItemView {
            $row = $this->lock($command->item, 'for no key update');

            if (Rows::string($row, 'status') === StockItemStatus::Archived->value) {
                return $this->find($command->item);
            }

            if ($this->hasOpenTransfer($command->item) || $this->levels->lockedItemNonEmpty($command->item->value)) {
                throw new StockItemNotEmpty(Rows::string($row, 'sku'));
            }

            $this->setStatus($command->item, StockItemStatus::Archived);

            return $this->find($command->item);
        });
    }

    public function restore(RestoreStockItem $command): StockItemView
    {
        return $this->transactions->run(function () use ($command): StockItemView {
            $row = $this->lock($command->item, 'for no key update');

            if (Rows::string($row, 'status') === StockItemStatus::Archived->value) {
                $this->setStatus($command->item, StockItemStatus::Active);
            }

            return $this->find($command->item);
        });
    }

    public function find(StockItemId $id): StockItemView
    {
        $row = $this->db()->table($this->tables->table('stock_items'))->where('id', $id->value)->first();

        return self::view($row ?? throw new StockItemNotFound($id->value));
    }

    public function findBySku(string $sku): StockItemView
    {
        $row = $this->db()->table($this->tables->table('stock_items'))->where('sku', $sku)->first();

        return self::view($row ?? throw new StockItemNotFound($sku));
    }

    public function list(ListStockItems $query): Page
    {
        $builder = $this->db()->table($this->tables->table('stock_items'))->orderBy('sku')->limit($query->first + 1);

        if ($query->status instanceof StockItemStatus) {
            $builder->where('status', $query->status->value);
        }

        if ($query->after instanceof Cursor) {
            $builder->where('sku', '>', $query->after->position());
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->first;
        $views = array_map(self::view(...), array_slice($rows, 0, $query->first));

        return new Page($views, $more && $views !== [] ? Cursor::after($views[array_key_last($views)]->sku) : null);
    }

    public function link(LinkSellable $command): StockLinkView
    {
        return $this->transactions->run(function () use ($command): StockLinkView {
            $db = $this->db();
            $this->lockActive($command->item, 'for share');
            $ref = (string) $command->sellable;
            $table = $this->tables->table('stock_item_links');

            $db->select('select pg_advisory_xact_lock(hashtext(?))', ['commerz.stock_links:'.$ref]);

            $exists = $db->table($table)
                ->where('sellable_ref', $ref)
                ->where('stock_item_id', $command->item->value)
                ->exists();

            if (! $exists && $db->table($table)->where('sellable_ref', $ref)->count() >= TooManyComponents::MAX) {
                throw new TooManyComponents($ref);
            }

            $now = Clock::now();
            $db->table($table)->upsert([[
                'id' => Uuid7::generate(),
                'sellable_ref' => $ref,
                'stock_item_id' => $command->item->value,
                'quantity_per_unit' => $command->quantityPerUnit,
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['sellable_ref', 'stock_item_id'], ['quantity_per_unit', 'updated_at']);

            $row = $this->linkRows()
                ->where('l.sellable_ref', $ref)
                ->where('l.stock_item_id', $command->item->value)
                ->first();

            return self::linkView($row ?? throw new StockItemNotFound($command->item->value));
        });
    }

    public function unlink(UnlinkSellable $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $this->db()->table($this->tables->table('stock_item_links'))
                ->where('sellable_ref', (string) $command->sellable)
                ->where('stock_item_id', $command->item->value)
                ->delete();
        });
    }

    public function links(ListLinks $query): Page
    {
        $builder = $this->linkRows()->orderBy('l.id')->limit($query->first + 1);

        if ($query->sellable instanceof SellableRef) {
            $builder->where('l.sellable_ref', (string) $query->sellable);
        }

        if ($query->item instanceof StockItemId) {
            $builder->where('l.stock_item_id', $query->item->value);
        }

        if ($query->after instanceof Cursor) {
            $position = $query->after->position();

            if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $position) !== 1) {
                throw new InvalidCommand('after', 'is not a valid cursor', 'common.invalid_cursor');
            }

            $builder->where('l.id', '>', $position);
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->first;
        $views = array_map(self::linkView(...), array_slice($rows, 0, $query->first));

        return new Page($views, $more && $views !== [] ? Cursor::after($views[array_key_last($views)]->id) : null);
    }

    private function linkRows(): Builder
    {
        return $this->db()->table($this->tables->table('stock_item_links').' as l')
            ->join($this->tables->table('stock_items').' as i', 'i.id', '=', 'l.stock_item_id')
            ->select(['l.id', 'l.sellable_ref', 'l.stock_item_id', 'l.quantity_per_unit', 'i.sku']);
    }

    private function setStatus(StockItemId $id, StockItemStatus $status): void
    {
        $this->db()->table($this->tables->table('stock_items'))
            ->where('id', $id->value)
            ->update(['status' => $status->value, 'updated_at' => Clock::now()]);
    }

    /** The caller holds the item row; new transfers cannot pass their item lock until this change commits. */
    private function hasOpenTransfer(StockItemId $item): bool
    {
        return $this->db()->table($this->tables->table('stock_transfer_lines').' as ln')
            ->join($this->tables->table('stock_transfers').' as t', 't.id', '=', 'ln.transfer_id')
            ->where('ln.stock_item_id', $item->value)
            ->whereIn('t.state', ['draft', 'in_transit'])
            ->whereRaw('ln.quantity - ln.received - ln.lost > 0')
            ->exists();
    }

    private function lock(StockItemId $id, string $mode): object
    {
        $row = $this->db()->table($this->tables->table('stock_items'))->where('id', $id->value)->lock($mode)->first();

        return $row ?? throw new StockItemNotFound($id->value);
    }

    private function lockActive(StockItemId $id, string $mode): object
    {
        $row = $this->lock($id, $mode);

        if (Rows::string($row, 'status') === StockItemStatus::Archived->value) {
            throw new StockItemArchived(Rows::string($row, 'sku'));
        }

        return $row;
    }

    private static function view(object $row): StockItemView
    {
        return new StockItemView(
            new StockItemId(Rows::string($row, 'id')),
            Rows::string($row, 'sku'),
            Rows::nullableString($row, 'name'),
            Rows::string($row, 'unit'),
            StockPolicy::from(Rows::string($row, 'policy')),
            StockItemStatus::from(Rows::string($row, 'status')),
            Rows::date($row, 'created_at'),
            Rows::date($row, 'updated_at'),
        );
    }

    private static function linkView(object $row): StockLinkView
    {
        return new StockLinkView(
            Rows::string($row, 'id'),
            SellableRef::fromString(Rows::string($row, 'sellable_ref')),
            new StockItemId(Rows::string($row, 'stock_item_id')),
            Rows::string($row, 'sku'),
            Rows::int($row, 'quantity_per_unit'),
        );
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
