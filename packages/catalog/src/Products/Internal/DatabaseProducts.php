<?php

declare(strict_types=1);

namespace Commerz\Catalog\Products\Internal;

use Commerz\Catalog\Attributes\Enums\AttributeStatus;
use Commerz\Catalog\Attributes\Internal\AttributeOwner;
use Commerz\Catalog\Attributes\Internal\AttributeWriter;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Common\Internal\BatchAttributeProcessor;
use Commerz\Catalog\Common\Internal\Documents;
use Commerz\Catalog\Products\Contracts\Products;
use Commerz\Catalog\Products\Data\ArchiveProduct;
use Commerz\Catalog\Products\Data\CreateProduct;
use Commerz\Catalog\Products\Data\ListProducts;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Data\ProductView;
use Commerz\Catalog\Products\Data\SetProductAttributes;
use Commerz\Catalog\Products\Data\UpdateProduct;
use Commerz\Catalog\Products\Data\UpsertProducts;
use Commerz\Catalog\Products\Data\UpsertProductsResult;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\Products\Events\ProductCreated;
use Commerz\Catalog\Products\Events\ProductsUpserted;
use Commerz\Catalog\Products\Events\ProductUpdated;
use Commerz\Catalog\Products\Exceptions\ProductArchived;
use Commerz\Catalog\Products\Exceptions\ProductNotFound;
use Commerz\Catalog\ProductTypes\Data\ProductTypeId;
use Commerz\Catalog\ProductTypes\Enums\AttributeLevel;
use Commerz\Catalog\ProductTypes\Enums\ProductTypeStatus;
use Commerz\Catalog\ProductTypes\Exceptions\ProductTypeArchived;
use Commerz\Catalog\ProductTypes\Exceptions\ProductTypeNotFound;
use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\Uuid7;
use Commerz\Measures\LocalizedText;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseProducts implements Products
{
    /** Rows per bulk `INSERT ... ON CONFLICT DO UPDATE` statement (9 columns x 500 = 4,500 bindings). */
    private const int UPSERT_CHUNK = 500;

    /** Maximum product IDs packed into one compressed outbox event. */
    private const int OUTBOX_EVENT_CHUNK = 5000;

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private CommittedEvents $events,
        private AttributeWriter $writer,
        private ProductQuery $query,
        private BatchAttributeProcessor $batchAttributes,
        private Outbox $outbox,
        private Clock $clock,
    ) {}

    public function create(CreateProduct $command): ProductView
    {
        return $this->transactions->run(function () use ($command): ProductView {
            $db = $this->db();

            // `for share` keeps the type from being archived until this product is in.
            $type = $db->table($this->tables->table('product_types'))
                ->where('id', $command->type->value)
                ->lock('for share')
                ->first() ?? throw new ProductTypeNotFound($command->type->value);

            if (Rows::string($type, 'status') === ProductTypeStatus::Archived->value) {
                throw new ProductTypeArchived(Rows::string($type, 'code'));
            }

            $id = new ProductId(Uuid7::generate());
            $now = PgTimestamp::now();
            // Every attribute check runs before the first write: a refused value leaves no product behind.
            $attributes = $this->writer->prepare(AttributeOwner::product($id), $command->type, [], $command->attributes, [], creating: true);

            try {
                // A nested transaction is a savepoint: a unique violation must not poison the caller's transaction.
                $db->transaction(fn () => $db->table($this->tables->table('products'))->insert([
                    'id' => $id->value,
                    'product_type_id' => $command->type->value,
                    'handle' => $command->handle,
                    'name' => Documents::encode($command->name->texts),
                    'description' => $command->description instanceof LocalizedText ? Documents::encode($command->description->texts) : null,
                    'status' => $command->status->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateCode('Product handle', $command->handle);
            }

            // The axes are a snapshot: later changes of the type do not reach existing products.
            $db->table($this->tables->table('product_options'))->insertUsing(
                ['product_id', 'option_id', 'position'],
                $db->table($this->tables->table('product_type_options'))
                    ->selectRaw('?::uuid, option_id, position', [$id->value])
                    ->where('product_type_id', $command->type->value),
            );

            $this->writer->apply($attributes);

            $view = $this->find($id);
            $this->events->dispatch(new ProductCreated($view));

            return $view;
        });
    }

    public function update(UpdateProduct $command): ProductView
    {
        return $this->transactions->run(function () use ($command): ProductView {
            $db = $this->db();
            $row = $this->lockWritable($command->product);
            $changes = [];

            if ($command->handle !== null && $command->handle !== Rows::string($row, 'handle')) {
                $changes['handle'] = $command->handle;
            }

            if ($command->name instanceof LocalizedText) {
                $changes['name'] = Documents::encode($command->name->texts);
            }

            if ($command->description instanceof LocalizedText) {
                $changes['description'] = Documents::encode($command->description->texts);
            }

            if ($command->status instanceof ProductStatus && $command->status->value !== Rows::string($row, 'status')) {
                $changes['status'] = $command->status->value;
            }

            if ($changes === []) {
                return $this->find($command->product);
            }

            try {
                $db->transaction(fn () => $db->table($this->tables->table('products'))
                    ->where('id', $command->product->value)
                    ->update($changes + ['updated_at' => PgTimestamp::now()]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateCode('Product handle', (string) $command->handle);
            }

            return $this->updated($command->product);
        });
    }

    public function setAttributes(SetProductAttributes $command): ProductView
    {
        return $this->transactions->run(function () use ($command): ProductView {
            $row = $this->lockWritable($command->product);

            $this->writer->write(
                AttributeOwner::product($command->product),
                new ProductTypeId(Rows::string($row, 'product_type_id')),
                Documents::decode(Rows::string($row, 'attributes')),
                $command->values,
                $command->remove,
                creating: false,
            );

            return $this->updated($command->product);
        });
    }

    public function archive(ArchiveProduct $command): ProductView
    {
        return $this->transactions->run(function () use ($command): ProductView {
            $row = $this->lock($command->product);

            if (Rows::string($row, 'status') === ProductStatus::Archived->value) {
                return $this->find($command->product);
            }

            $this->db()->table($this->tables->table('products'))
                ->where('id', $command->product->value)
                ->update(['status' => ProductStatus::Archived->value, 'updated_at' => PgTimestamp::now()]);

            return $this->updated($command->product);
        });
    }

    public function find(ProductId $id): ProductView
    {
        $row = $this->db()->table($this->tables->table('products'))->where('id', $id->value)->first();

        return $this->views([$row ?? throw new ProductNotFound($id->value)])[0];
    }

    public function findByHandle(string $handle): ProductView
    {
        $row = $this->db()->table($this->tables->table('products'))->where('handle', $handle)->first();

        return $this->views([$row ?? throw new ProductNotFound($handle)])[0];
    }

    public function upsertMany(UpsertProducts $command): UpsertProductsResult
    {
        if ($command->items === []) {
            return new UpsertProductsResult([], [], [], 0, 0, 0, 0);
        }

        return $this->transactions->run(function () use ($command): UpsertProductsResult {
            $db = $this->db();
            $isPg = $db instanceof Connection && $db->getDriverName() === 'pgsql';
            $productsTable = $this->tables->table('products');

            // 1. Validate & lock all referenced product types in one query.
            $typeIds = array_values(array_unique(array_map(
                static fn ($item): string => $item->type->value,
                $command->items,
            )));
            sort($typeIds);

            $typesById = [];

            foreach (array_chunk($typeIds, self::UPSERT_CHUNK) as $chunk) {
                $typeQuery = $db->table($this->tables->table('product_types'))
                    ->whereIn('id', $chunk)
                    ->orderBy('id');

                if ($isPg) {
                    $typeQuery->lock('for share');
                }

                foreach ($typeQuery->get() as $typeRow) {
                    $typesById[Rows::string($typeRow, 'id')] = $typeRow;
                }
            }

            foreach ($typeIds as $typeId) {
                $typeRow = $typesById[$typeId] ?? throw new ProductTypeNotFound($typeId);

                if (Rows::string($typeRow, 'status') === ProductTypeStatus::Archived->value) {
                    throw new ProductTypeArchived(Rows::string($typeRow, 'code'));
                }
            }

            // 2. Load & lock existing products by handle in chunked queries.
            $handles = array_map(static fn ($item): string => $item->handle, $command->items);
            $existingByHandle = [];

            foreach (array_chunk($handles, self::UPSERT_CHUNK) as $chunk) {
                $existingQuery = $db->table($productsTable)
                    ->whereIn('handle', $chunk)
                    ->orderBy('id');

                if ($isPg) {
                    $existingQuery->lock('for no key update');
                }

                foreach ($existingQuery->get() as $row) {
                    $existingByHandle[Rows::string($row, 'handle')] = $row;
                }
            }

            // 3. Plan IDs, verify immutability rules, and batch-validate every attribute BEFORE any write.
            $planned = [];
            $attrInputs = [];
            $createdIds = [];
            $updatedIds = [];
            $archivedIds = [];
            $newByType = [];

            foreach ($command->items as $item) {
                $existing = $existingByHandle[$item->handle] ?? null;

                if ($existing !== null) {
                    $existingTypeId = Rows::string($existing, 'product_type_id');

                    if ($existingTypeId !== $item->type->value) {
                        throw new InvalidCommand(
                            'type',
                            "cannot change product type of existing product '{$item->handle}'",
                            'catalog.invalid_product_type',
                        );
                    }

                    $id = Rows::string($existing, 'id');
                    $currentDoc = Documents::decode(Rows::string($existing, 'attributes'));
                    $creating = false;
                    $updatedIds[] = $id;
                } else {
                    if ($item->status === ProductStatus::Archived) {
                        throw new InvalidCommand('status', 'a product cannot be created archived', 'catalog.invalid_status_transition');
                    }

                    $id = Uuid7::generate();
                    $currentDoc = [];
                    $creating = true;
                    $createdIds[] = $id;
                    $newByType[$item->type->value][] = $id;
                }

                if ($item->status === ProductStatus::Archived) {
                    $archivedIds[] = $id;
                }

                $planned[] = [
                    'id' => $id,
                    'item' => $item,
                    'creating' => $creating,
                ];

                $attrInputs[] = [
                    'productId' => $id,
                    'variantId' => null,
                    'typeId' => $item->type->value,
                    'current' => $currentDoc,
                    'set' => $item->attributes,
                    'creating' => $creating,
                    'replace' => $item->replaceAttributes,
                ];
            }

            $prepared = $this->batchAttributes->prepareBatch($db, AttributeLevel::Product, $attrInputs);
            $now = PgTimestamp::now($this->clock);
            $wrappedProducts = $db->table($productsTable)->getGrammar()->wrapTable($productsTable);
            $rowPlaceholder = $isPg
                ? '(?::uuid, ?::uuid, ?, ?::jsonb, ?::jsonb, ?, ?::jsonb, ?::timestamptz, ?::timestamptz)'
                : '(?, ?, ?, ?, ?, ?, ?, ?, ?)';

            // 4. Bulk INSERT ... ON CONFLICT (handle) DO UPDATE in chunks.
            foreach (array_chunk($planned, self::UPSERT_CHUNK) as $chunk) {
                $placeholders = [];
                $bindings = [];

                foreach ($chunk as $entry) {
                    $item = $entry['item'];
                    $id = $entry['id'];
                    $placeholders[] = $rowPlaceholder;
                    $bindings[] = $id;
                    $bindings[] = $item->type->value;
                    $bindings[] = $item->handle;
                    $bindings[] = Documents::encode($item->name->texts);
                    $bindings[] = $item->description instanceof LocalizedText ? Documents::encode($item->description->texts) : null;
                    $bindings[] = $item->status->value;
                    $bindings[] = Documents::encode($prepared['documents'][$id] ?? []);
                    $bindings[] = $now;
                    $bindings[] = $now;
                }

                $db->select(
                    "insert into {$wrappedProducts} (id, product_type_id, handle, name, description, status, attributes, created_at, updated_at)"
                    .' values '.implode(', ', $placeholders)
                    .' on conflict (handle) do update set'
                    .' name = excluded.name,'
                    .' description = excluded.description,'
                    .' status = excluded.status,'
                    .' attributes = excluded.attributes,'
                    .' updated_at = excluded.updated_at'
                    .' returning id',
                    $bindings,
                    useReadPdo: false,
                );
            }

            // 5. Snapshot product_options for newly created products in bulk.
            if ($newByType !== []) {
                $typeOptionRows = $db->table($this->tables->table('product_type_options'))
                    ->whereIn('product_type_id', array_keys($newByType))
                    ->select('product_type_id', 'option_id', 'position')
                    ->get();

                $optionsByType = [];

                foreach ($typeOptionRows as $row) {
                    $optionsByType[Rows::string($row, 'product_type_id')][] = [
                        'option_id' => Rows::string($row, 'option_id'),
                        'position' => Rows::int($row, 'position'),
                    ];
                }

                $productOptionRows = [];

                foreach ($newByType as $typeId => $newProductIds) {
                    foreach ($optionsByType[$typeId] ?? [] as $opt) {
                        foreach ($newProductIds as $productId) {
                            $productOptionRows[] = [
                                'product_id' => $productId,
                                'option_id' => $opt['option_id'],
                                'position' => $opt['position'],
                            ];
                        }
                    }
                }

                if ($productOptionRows !== []) {
                    $poTable = $this->tables->table('product_options');
                    $wrappedPo = $db->table($poTable)->getGrammar()->wrapTable($poTable);
                    $poPlaceholder = $isPg ? '(?::uuid, ?::uuid, ?)' : '(?, ?, ?)';

                    foreach (array_chunk($productOptionRows, self::UPSERT_CHUNK) as $chunk) {
                        $placeholders = [];
                        $bindings = [];

                        foreach ($chunk as $poRow) {
                            $placeholders[] = $poPlaceholder;
                            $bindings[] = $poRow['product_id'];
                            $bindings[] = $poRow['option_id'];
                            $bindings[] = $poRow['position'];
                        }

                        $db->insert(
                            "insert into {$wrappedPo} (product_id, option_id, position) values ".implode(', ', $placeholders)
                            .' on conflict (product_id, option_id) do nothing',
                            $bindings,
                        );
                    }
                }
            }

            // 6. Grouped reindex of cmz_attribute_index for all upserted products.
            $allIds = array_map(static fn (array $entry): string => $entry['id'], $planned);
            $this->batchAttributes->reindexProducts($db, $allIds, $prepared['facts']);

            // 7. Emit compressed Outbox event(s) for downstream projections and external search adapters.
            $batchId = Uuid7::generate();
            $occurredAt = PgTimestamp::current($this->clock);
            $outboxEventCount = 0;
            $createdSet = array_fill_keys($createdIds, true);
            $archivedSet = array_fill_keys($archivedIds, true);

            foreach (array_chunk($allIds, self::OUTBOX_EVENT_CHUNK) as $chunkIds) {
                $chunkCreated = array_values(array_filter($chunkIds, static fn (string $id): bool => isset($createdSet[$id])));
                $chunkUpdated = array_values(array_filter($chunkIds, static fn (string $id): bool => ! isset($createdSet[$id])));
                $chunkArchived = array_values(array_filter($chunkIds, static fn (string $id): bool => isset($archivedSet[$id])));

                $this->outbox->record(IntegrationEvent::of(
                    type: 'catalog.products_upserted',
                    aggregateRef: 'catalog:batch',
                    payload: [
                        'batch_id' => $batchId,
                        'product_ids' => $chunkIds,
                        'created_ids' => $chunkCreated,
                        'updated_ids' => $chunkUpdated,
                        'archived_ids' => $chunkArchived,
                        'count' => count($chunkIds),
                    ],
                    occurredAt: $occurredAt,
                ));
                $outboxEventCount++;
            }

            // 8. Hydrate ProductViews in batched queries and preserve input order.
            $rowsById = [];

            foreach (array_chunk($allIds, self::UPSERT_CHUNK) as $chunk) {
                foreach ($db->table($productsTable)->whereIn('id', $chunk)->get() as $row) {
                    $rowsById[Rows::string($row, 'id')] = $row;
                }
            }

            $orderedRows = [];

            foreach ($allIds as $id) {
                if (isset($rowsById[$id])) {
                    $orderedRows[] = $rowsById[$id];
                }
            }

            $views = $this->views($orderedRows);
            $createdProductIds = array_map(static fn (string $id): ProductId => new ProductId($id), $createdIds);
            $updatedProductIds = array_map(static fn (string $id): ProductId => new ProductId($id), $updatedIds);

            $this->events->dispatch(new ProductsUpserted(
                batchId: $batchId,
                createdIds: $createdProductIds,
                updatedIds: $updatedProductIds,
                count: count($views),
            ));

            return new UpsertProductsResult(
                products: $views,
                createdIds: $createdProductIds,
                updatedIds: $updatedProductIds,
                createdCount: count($createdProductIds),
                updatedCount: count($updatedProductIds),
                indexedFactCount: count($prepared['facts']),
                outboxEventCount: $outboxEventCount,
            );
        });
    }

    public function list(ListProducts $query): Page
    {
        $rows = $this->query->rows($query);
        $more = count($rows) > $query->first;
        $views = $this->views(array_slice($rows, 0, $query->first));

        return new Page($views, $more && $views !== [] ? Cursor::after($views[array_key_last($views)]->handle) : null);
    }

    /** `for no key update`, not `for update`: projection inserts take `for key share` on the product (FK check). */
    private function lock(ProductId $id): object
    {
        $row = $this->db()->table($this->tables->table('products'))->where('id', $id->value)->lock('for no key update')->first();

        return $row ?? throw new ProductNotFound($id->value);
    }

    private function lockWritable(ProductId $id): object
    {
        $row = $this->lock($id);

        if (Rows::string($row, 'status') === ProductStatus::Archived->value) {
            throw new ProductArchived(Rows::string($row, 'handle'));
        }

        return $row;
    }

    private function updated(ProductId $id): ProductView
    {
        $view = $this->find($id);
        $this->events->dispatch(new ProductUpdated($view));

        return $view;
    }

    /**
     * @param  list<object>  $rows
     * @return list<ProductView>
     */
    private function views(array $rows): array
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

        $retired = $this->retired($db, $codes);
        $axes = [];

        foreach (array_chunk(array_keys($documents), self::UPSERT_CHUNK) as $chunk) {
            $axisRows = $db->table($this->tables->table('product_options').' as p')
                ->join($this->tables->table('options').' as o', 'o.id', '=', 'p.option_id')
                ->whereIn('p.product_id', $chunk)
                ->orderBy('p.position')
                ->select('p.product_id', 'o.code')
                ->get();

            foreach ($axisRows as $axis) {
                $axes[Rows::string($axis, 'product_id')][] = Rows::string($axis, 'code');
            }
        }

        return array_map(static function (object $row) use ($documents, $retired, $axes): ProductView {
            $id = Rows::string($row, 'id');

            return new ProductView(
                new ProductId($id),
                new ProductTypeId(Rows::string($row, 'product_type_id')),
                Rows::string($row, 'handle'),
                Rows::localized($row, 'name'),
                Rows::nullableString($row, 'description') === null ? null : Rows::localized($row, 'description'),
                ProductStatus::from(Rows::string($row, 'status')),
                self::sorted(array_diff_key($documents[$id], $retired)),
                $axes[$id] ?? [],
                Rows::date($row, 'created_at'),
                Rows::date($row, 'updated_at'),
            );
        }, $rows);
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     * @return array<array-key, mixed> ordered by code (JSONB keeps its own key order)
     */
    private static function sorted(array $attributes): array
    {
        ksort($attributes, SORT_STRING);

        return $attributes;
    }

    /**
     * @param  list<string>  $codes
     * @return array<array-key, true> retired code => true
     */
    private function retired(ConnectionInterface $db, array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $retired = $db->table($this->tables->table('attributes'))
            ->whereIn('code', array_values(array_unique($codes)))
            ->where('status', AttributeStatus::Retired->value)
            ->pluck('code')
            ->all();

        return array_fill_keys(array_filter($retired, is_string(...)), true);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
