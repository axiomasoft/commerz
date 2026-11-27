<?php

declare(strict_types=1);

namespace Commerz\Catalog\Collections\Internal;

use Commerz\Catalog\Attributes\Enums\AttributeStatus;
use Commerz\Catalog\Collections\Contracts\Collections;
use Commerz\Catalog\Collections\Data\ArchiveCollection;
use Commerz\Catalog\Collections\Data\CollectionId;
use Commerz\Catalog\Collections\Data\CollectionItem;
use Commerz\Catalog\Collections\Data\CollectionProductView;
use Commerz\Catalog\Collections\Data\CollectionView;
use Commerz\Catalog\Collections\Data\CreateCollection;
use Commerz\Catalog\Collections\Data\ListCollectionProducts;
use Commerz\Catalog\Collections\Data\ListCollections;
use Commerz\Catalog\Collections\Data\PutCollectionProducts;
use Commerz\Catalog\Collections\Data\RemoveCollectionProducts;
use Commerz\Catalog\Collections\Data\SetCollectionProducts;
use Commerz\Catalog\Collections\Data\SmartCollection;
use Commerz\Catalog\Collections\Data\UpdateCollection;
use Commerz\Catalog\Collections\Enums\CollectionKind;
use Commerz\Catalog\Collections\Enums\CollectionStatus;
use Commerz\Catalog\Collections\Events\CollectionCreated;
use Commerz\Catalog\Collections\Events\CollectionUpdated;
use Commerz\Catalog\Collections\Exceptions\CollectionArchived;
use Commerz\Catalog\Collections\Exceptions\CollectionNotFound;
use Commerz\Catalog\Collections\Exceptions\InvalidCollectionOperation;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Common\Internal\Documents;
use Commerz\Catalog\Listings\Enums\ListingStatus;
use Commerz\Catalog\Products\Data\ListProducts;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Data\ProductView;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\Products\Exceptions\ProductNotFound;
use Commerz\Catalog\Products\Internal\FilterCompiler;
use Commerz\Catalog\Products\Internal\ProductQuery;
use Commerz\Catalog\ProductTypes\Data\ProductTypeId;
use Commerz\Catalog\Variants\Enums\VariantStatus;
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
use Commerz\Stores\Data\ChannelId;
use Commerz\Time\PgTimestamp;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseCollections implements Collections
{
    private const int INSERT_CHUNK = 1000;

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private CommittedEvents $events,
        private FilterCompiler $filters,
        private ProductQuery $productQuery,
    ) {}

    public function create(CreateCollection $command): CollectionView
    {
        if ($command->smart instanceof SmartCollection) {
            // Validate every attribute/variant filter against live definitions before writing.
            $this->filters->compile($command->smart->filters);
        }

        return $this->transactions->run(function () use ($command): CollectionView {
            $db = $this->db();
            $id = Uuid7::generate();
            $now = PgTimestamp::now();
            $rulesJson = $command->smart instanceof SmartCollection
                ? json_encode($command->smart->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)
                : null;

            try {
                $db->transaction(fn () => $db->table($this->tables->table('collections'))->insert([
                    'id' => $id,
                    'code' => $command->code,
                    'name' => Documents::encode($command->name->texts),
                    'description' => $command->description instanceof LocalizedText ? Documents::encode($command->description->texts) : null,
                    'kind' => $command->kind->value,
                    'rules' => $rulesJson,
                    'rules_version' => SmartCollection::VERSION,
                    'status' => CollectionStatus::Active->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateCode('Collection', $command->code);
            }

            $view = $this->find(new CollectionId($id));
            $this->events->dispatch(new CollectionCreated($view));

            return $view;
        });
    }

    public function update(UpdateCollection $command): CollectionView
    {
        if ($command->smart instanceof SmartCollection) {
            $this->filters->compile($command->smart->filters);
        }

        return $this->transactions->run(function () use ($command): CollectionView {
            $db = $this->db();
            $row = $this->lockActive($command->collection);
            $kind = CollectionKind::from(Rows::string($row, 'kind'));
            $code = Rows::string($row, 'code');

            if ($command->smart instanceof SmartCollection && $kind !== CollectionKind::Smart) {
                throw new InvalidCollectionOperation($code, 'manual collections do not accept smart filter rules');
            }

            $changes = [];

            if ($command->name instanceof LocalizedText) {
                $changes['name'] = Documents::encode($command->name->texts);
            }

            if ($command->description instanceof LocalizedText) {
                $changes['description'] = Documents::encode($command->description->texts);
            } elseif ($command->clearDescription) {
                $changes['description'] = null;
            }

            if ($command->smart instanceof SmartCollection) {
                $changes['rules'] = json_encode(
                    $command->smart->toArray(),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
                );
                $changes['rules_version'] = SmartCollection::VERSION;
            }

            if ($changes === []) {
                return $this->find($command->collection);
            }

            $db->table($this->tables->table('collections'))
                ->where('id', $command->collection->value)
                ->update($changes + ['updated_at' => PgTimestamp::now()]);

            $view = $this->find($command->collection);
            $this->events->dispatch(new CollectionUpdated($view));

            return $view;
        });
    }

    public function archive(ArchiveCollection $command): CollectionView
    {
        return $this->transactions->run(function () use ($command): CollectionView {
            $row = $this->lock($command->collection);

            if (Rows::string($row, 'status') === CollectionStatus::Archived->value) {
                return $this->find($command->collection);
            }

            $this->db()->table($this->tables->table('collections'))
                ->where('id', $command->collection->value)
                ->update(['status' => CollectionStatus::Archived->value, 'updated_at' => PgTimestamp::now()]);

            $view = $this->find($command->collection);
            $this->events->dispatch(new CollectionUpdated($view));

            return $view;
        });
    }

    public function find(CollectionId $id): CollectionView
    {
        $row = $this->db()->table($this->tables->table('collections'))->where('id', $id->value)->first();

        return self::view($row ?? throw new CollectionNotFound($id->value));
    }

    public function findByCode(string $code): CollectionView
    {
        $row = $this->db()->table($this->tables->table('collections'))->where('code', $code)->first();

        return self::view($row ?? throw new CollectionNotFound($code));
    }

    public function list(ListCollections $query): Page
    {
        $builder = $this->db()->table($this->tables->table('collections'))
            ->orderBy('code')
            ->limit($query->first + 1);

        if ($query->kind instanceof CollectionKind) {
            $builder->where('kind', $query->kind->value);
        }

        if (! $query->includeArchived) {
            $builder->where('status', CollectionStatus::Active->value);
        }

        if ($query->after instanceof Cursor) {
            $builder->where('code', '>', $query->after->position());
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->first;
        $views = array_map(self::view(...), array_slice($rows, 0, $query->first));

        return new Page($views, $more && $views !== [] ? Cursor::after($views[array_key_last($views)]->code) : null);
    }

    public function setProducts(SetCollectionProducts $command): array
    {
        return $this->transactions->run(function () use ($command): array {
            $db = $this->db();
            $this->lockManual($command->collection);
            $this->assertProductsExist($db, array_map(static fn (CollectionItem $item): string => $item->product->value, $command->items));

            $links = $this->tables->table('collection_products');
            $db->table($links)->where('collection_id', $command->collection->value)->delete();

            $rows = array_map(static fn (CollectionItem $item): array => [
                'collection_id' => $command->collection->value,
                'product_id' => $item->product->value,
                'position' => $item->position,
            ], $command->items);

            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                $db->table($links)->insert($chunk);
            }

            $db->table($this->tables->table('collections'))
                ->where('id', $command->collection->value)
                ->update(['updated_at' => PgTimestamp::now()]);

            $this->events->dispatch(new CollectionUpdated($this->find($command->collection)));

            return $this->allManualProducts($db, $command->collection);
        });
    }

    public function putProducts(PutCollectionProducts $command): array
    {
        return $this->transactions->run(function () use ($command): array {
            $db = $this->db();
            $this->lockManual($command->collection);
            $this->assertProductsExist($db, array_map(static fn ($item): string => $item->product->value, $command->items));

            $links = $this->tables->table('collection_products');
            $rows = array_map(static fn ($item): array => [
                'collection_id' => $command->collection->value,
                'product_id' => $item->product->value,
                'position' => $item->position,
            ], $command->items);

            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                $db->table($links)->upsert($chunk, ['collection_id', 'product_id'], ['position']);
            }

            $db->table($this->tables->table('collections'))
                ->where('id', $command->collection->value)
                ->update(['updated_at' => PgTimestamp::now()]);

            $this->events->dispatch(new CollectionUpdated($this->find($command->collection)));

            return $this->allManualProducts($db, $command->collection);
        });
    }

    public function removeProducts(RemoveCollectionProducts $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $db = $this->db();
            $this->lockManual($command->collection);
            $ids = array_map(static fn (ProductId $product): string => $product->value, $command->products);

            $deleted = $db->table($this->tables->table('collection_products'))
                ->where('collection_id', $command->collection->value)
                ->whereIn('product_id', $ids)
                ->delete();

            if ($deleted > 0) {
                $db->table($this->tables->table('collections'))
                    ->where('id', $command->collection->value)
                    ->update(['updated_at' => PgTimestamp::now()]);

                $this->events->dispatch(new CollectionUpdated($this->find($command->collection)));
            }
        });
    }

    public function products(ListCollectionProducts $query): Page
    {
        $collection = $this->find($query->collection);

        if ($collection->kind === CollectionKind::Smart) {
            return $this->smartProducts($collection, $query);
        }

        return $this->manualProducts($collection, $query);
    }

    public function ofProduct(ProductId $product): array
    {
        $db = $this->db();

        if (! $db->table($this->tables->table('products'))->where('id', $product->value)->exists()) {
            throw new ProductNotFound($product->value);
        }

        $rows = $db->table($this->tables->table('collections').' as c')
            ->join($this->tables->table('collection_products').' as cp', 'cp.collection_id', '=', 'c.id')
            ->where('cp.product_id', $product->value)
            ->where('c.status', CollectionStatus::Active->value)
            ->orderBy('c.code')
            ->select('c.*')
            ->get()
            ->all();

        return array_values(array_map(self::view(...), $rows));
    }

    /** @return Page<CollectionProductView> */
    private function smartProducts(CollectionView $collection, ListCollectionProducts $query): Page
    {
        $smartFilters = $collection->smart instanceof SmartCollection ? $collection->smart->filters : [];
        $combined = [...$smartFilters, ...$query->filters];

        if (count($combined) > ListProducts::MAX_FILTERS) {
            throw new InvalidCommand('filters', 'combined smart collection and query filters exceed '.ListProducts::MAX_FILTERS, 'catalog.invalid_filter');
        }

        $rows = $this->productQuery->rows(new ListProducts(
            channel: $query->channel,
            status: $query->status,
            filters: $combined,
            at: $query->at,
            first: $query->first,
            after: $query->after,
        ));

        $more = count($rows) > $query->first;
        $productViews = $this->productViews(array_slice($rows, 0, $query->first));
        $items = array_map(static fn (ProductView $view): CollectionProductView => new CollectionProductView($view, null), $productViews);

        return new Page(
            $items,
            $more && $productViews !== [] ? Cursor::after($productViews[array_key_last($productViews)]->handle) : null,
        );
    }

    /** @return Page<CollectionProductView> */
    private function manualProducts(CollectionView $collection, ListCollectionProducts $query): Page
    {
        $conditions = $this->filters->compile($query->filters);

        if ($conditions === null) {
            return new Page([]);
        }

        $db = $this->db();
        $builder = $db->table($this->tables->table('collection_products').' as cp')
            ->join($this->tables->table('products').' as p', 'p.id', '=', 'cp.product_id')
            ->where('cp.collection_id', $collection->id->value)
            ->select('p.*', 'cp.position as collection_position')
            ->orderBy('cp.position')
            ->orderBy('p.handle')
            ->limit($query->first + 1);

        if ($query->channel instanceof ChannelId) {
            $builder->where('p.status', ProductStatus::Active->value);
            $this->listedIn($builder, $query->channel, $query->at);
        } elseif ($query->status instanceof ProductStatus) {
            $builder->where('p.status', $query->status->value);
        } else {
            $builder->where('p.status', '<>', ProductStatus::Archived->value);
        }

        if ($conditions !== []) {
            $variants = $this->tables->table('variants');
            $builder->whereExists(static function (Builder $v) use ($variants, $conditions): void {
                $v->selectRaw('1')
                    ->from($variants.' as v')
                    ->whereColumn('v.product_id', 'p.id')
                    ->where('v.status', VariantStatus::Active->value);

                foreach ($conditions as $condition) {
                    $condition($v);
                }
            });
        }

        if ($query->after instanceof Cursor) {
            [$afterPos, $afterHandle] = self::manualCursor($query->after);
            $builder->where(static function (Builder $after) use ($afterPos, $afterHandle): void {
                $after->where('cp.position', '>', $afterPos)
                    ->orWhere(static fn (Builder $tie) => $tie->where('cp.position', $afterPos)->where('p.handle', '>', $afterHandle));
            });
        }

        $rows = array_values($builder->get()->all());
        $more = count($rows) > $query->first;
        $sliced = array_slice($rows, 0, $query->first);
        $productViews = $this->productViews($sliced);
        $items = [];

        foreach ($sliced as $index => $row) {
            $items[] = new CollectionProductView($productViews[$index], Rows::int($row, 'collection_position'));
        }

        $last = $items === [] ? null : $items[array_key_last($items)];

        return new Page(
            $items,
            $more && $last instanceof CollectionProductView ? Cursor::after("{$last->position}:{$last->product->handle}") : null,
        );
    }

    /** @return list<CollectionProductView> */
    private function allManualProducts(ConnectionInterface $db, CollectionId $collection): array
    {
        $rows = array_values($db->table($this->tables->table('collection_products').' as cp')
            ->join($this->tables->table('products').' as p', 'p.id', '=', 'cp.product_id')
            ->where('cp.collection_id', $collection->value)
            ->orderBy('cp.position')
            ->orderBy('p.handle')
            ->select('p.*', 'cp.position as collection_position')
            ->get()
            ->all());

        $productViews = $this->productViews($rows);
        $items = [];

        foreach ($rows as $index => $row) {
            $items[] = new CollectionProductView($productViews[$index], Rows::int($row, 'collection_position'));
        }

        return $items;
    }

    private function listedIn(Builder $builder, ChannelId $channel, ?DateTimeImmutable $at): void
    {
        $listings = $this->tables->table('listings');
        $moment = $at instanceof DateTimeImmutable ? PgTimestamp::format($at) : PgTimestamp::now();

        $builder->whereExists(static fn (Builder $l) => $l->selectRaw('1')
            ->from($listings.' as l')
            ->whereColumn('l.product_id', 'p.id')
            ->where('l.channel_id', $channel->value)
            ->where('l.status', ListingStatus::Published->value)
            ->where(static fn (Builder $from) => $from->whereNull('l.available_from')->orWhere('l.available_from', '<=', $moment))
            ->where(static fn (Builder $until) => $until->whereNull('l.available_until')->orWhere('l.available_until', '>', $moment)));
    }

    /**
     * @param  list<string>  $ids
     */
    private function assertProductsExist(ConnectionInterface $db, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $found = $db->table($this->tables->table('products'))
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lock('for share')
            ->pluck('id')
            ->all();

        $known = array_fill_keys(array_filter($found, is_string(...)), true);

        foreach ($ids as $id) {
            if (! isset($known[$id])) {
                throw new ProductNotFound($id);
            }
        }
    }

    private function lock(CollectionId $id): object
    {
        $row = $this->db()->table($this->tables->table('collections'))->where('id', $id->value)->lock('for no key update')->first();

        return $row ?? throw new CollectionNotFound($id->value);
    }

    private function lockActive(CollectionId $id): object
    {
        $row = $this->lock($id);

        if (Rows::string($row, 'status') === CollectionStatus::Archived->value) {
            throw new CollectionArchived(Rows::string($row, 'code'));
        }

        return $row;
    }

    private function lockManual(CollectionId $id): object
    {
        $row = $this->lockActive($id);

        if (CollectionKind::from(Rows::string($row, 'kind')) !== CollectionKind::Manual) {
            throw new InvalidCollectionOperation(Rows::string($row, 'code'), 'smart collections compute membership dynamically');
        }

        return $row;
    }

    /** @return array{int, string} */
    private static function manualCursor(Cursor $cursor): array
    {
        $parts = explode(':', $cursor->position(), 2);
        $position = filter_var($parts[0], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if (count($parts) !== 2 || $position === false || $parts[1] === '') {
            throw new InvalidCommand('after', 'is not a valid cursor', 'common.invalid_cursor');
        }

        return [$position, $parts[1]];
    }

    private static function view(object $row): CollectionView
    {
        $kind = CollectionKind::from(Rows::string($row, 'kind'));
        $rulesRaw = Rows::nullableString($row, 'rules');
        $smart = null;

        if ($kind === CollectionKind::Smart && $rulesRaw !== null) {
            $decoded = json_decode($rulesRaw, true, 512, JSON_THROW_ON_ERROR);
            $smart = is_array($decoded) ? SmartCollection::fromArray($decoded) : null;
        }

        return new CollectionView(
            new CollectionId(Rows::string($row, 'id')),
            Rows::string($row, 'code'),
            Rows::localized($row, 'name'),
            Rows::nullableString($row, 'description') === null ? null : Rows::localized($row, 'description'),
            $kind,
            $smart,
            CollectionStatus::from(Rows::string($row, 'status')),
            Rows::date($row, 'created_at'),
            Rows::date($row, 'updated_at'),
        );
    }

    /**
     * @param  list<object>  $rows
     * @return list<ProductView>
     */
    private function productViews(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $db = $this->db();
        $documents = [];
        $codes = [];

        foreach ($rows as $row) {
            $document = Documents::decode(Rows::string($row, 'attributes'));
            $documents[Rows::string($row, 'id')] = $document;
            array_push($codes, ...array_map(strval(...), array_keys($document)));
        }

        $retired = [];

        if ($codes !== []) {
            $retiredCodes = $db->table($this->tables->table('attributes'))
                ->whereIn('code', array_values(array_unique($codes)))
                ->where('status', AttributeStatus::Retired->value)
                ->pluck('code')
                ->all();
            $retired = array_fill_keys(array_filter($retiredCodes, is_string(...)), true);
        }

        $axes = [];
        $axisRows = $db->table($this->tables->table('product_options').' as p')
            ->join($this->tables->table('options').' as o', 'o.id', '=', 'p.option_id')
            ->whereIn('p.product_id', array_keys($documents))
            ->orderBy('p.position')
            ->select('p.product_id', 'o.code')
            ->get();

        foreach ($axisRows as $axis) {
            $axes[Rows::string($axis, 'product_id')][] = Rows::string($axis, 'code');
        }

        return array_map(static function (object $row) use ($documents, $retired, $axes): ProductView {
            $id = Rows::string($row, 'id');
            $filtered = array_diff_key($documents[$id], $retired);
            ksort($filtered, SORT_STRING);

            return new ProductView(
                new ProductId($id),
                new ProductTypeId(Rows::string($row, 'product_type_id')),
                Rows::string($row, 'handle'),
                Rows::localized($row, 'name'),
                Rows::nullableString($row, 'description') === null ? null : Rows::localized($row, 'description'),
                ProductStatus::from(Rows::string($row, 'status')),
                $filtered,
                $axes[$id] ?? [],
                Rows::date($row, 'created_at'),
                Rows::date($row, 'updated_at'),
            );
        }, $rows);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
