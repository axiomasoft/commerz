<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Internal;

use Commerz\Catalog\Attributes\Enums\AttributeStatus;
use Commerz\Catalog\Attributes\Internal\AttributeOwner;
use Commerz\Catalog\Attributes\Internal\AttributeWriter;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Common\Internal\BatchAttributeProcessor;
use Commerz\Catalog\Common\Internal\Documents;
use Commerz\Catalog\Configuration\CatalogOptions;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\Products\Exceptions\ProductArchived;
use Commerz\Catalog\Products\Exceptions\ProductNotFound;
use Commerz\Catalog\ProductTypes\Data\ProductTypeId;
use Commerz\Catalog\ProductTypes\Enums\AttributeLevel;
use Commerz\Catalog\Variants\Contracts\Variants;
use Commerz\Catalog\Variants\Data\ArchiveVariant;
use Commerz\Catalog\Variants\Data\CreateVariant;
use Commerz\Catalog\Variants\Data\Dimensions;
use Commerz\Catalog\Variants\Data\GeneratedVariants;
use Commerz\Catalog\Variants\Data\GenerateVariants;
use Commerz\Catalog\Variants\Data\ListVariants;
use Commerz\Catalog\Variants\Data\PlannedVariant;
use Commerz\Catalog\Variants\Data\RestoreVariant;
use Commerz\Catalog\Variants\Data\SetVariantAttributes;
use Commerz\Catalog\Variants\Data\UpdateVariant;
use Commerz\Catalog\Variants\Data\UpsertVariantItem;
use Commerz\Catalog\Variants\Data\UpsertVariants;
use Commerz\Catalog\Variants\Data\UpsertVariantsResult;
use Commerz\Catalog\Variants\Data\VariantId;
use Commerz\Catalog\Variants\Data\VariantView;
use Commerz\Catalog\Variants\Enums\VariantStatus;
use Commerz\Catalog\Variants\Events\VariantCreated;
use Commerz\Catalog\Variants\Events\VariantsUpserted;
use Commerz\Catalog\Variants\Events\VariantUpdated;
use Commerz\Catalog\Variants\Exceptions\DuplicateCombination;
use Commerz\Catalog\Variants\Exceptions\InvalidAxes;
use Commerz\Catalog\Variants\Exceptions\TooManyVariants;
use Commerz\Catalog\Variants\Exceptions\VariantNotFound;
use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Identity\Uuid7;
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
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\UniqueConstraintViolationException;
use Packman\Attributes\Internal;
use Packman\Schema\Data\Compiler\ConstraintNames;
use Throwable;

/**
 * CAT-01: every change of a product's set of variants starts by locking the product row `for no key update`, so
 * concurrent creations and generations of one product run one after another and see each other's combinations.
 * `UNIQUE(product_id, option_signature)` and `on conflict do nothing` are the safety net, not the mechanism.
 */
#[Internal]
final readonly class DatabaseVariants implements Variants
{
    /** Variant rows per generated INSERT; 7 bindings each stay far below the 65535 bind limit. */
    private const int INSERT_CHUNK = 1000;

    /** Variant rows per bulk `INSERT ... ON CONFLICT (sku) DO UPDATE` statement (15 cols x 500 = 7,500 bindings). */
    private const int UPSERT_CHUNK = 500;

    /** Maximum variant IDs packed into one compressed outbox event. */
    private const int OUTBOX_EVENT_CHUNK = 5000;

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private CatalogOptions $catalog,
        private TableNames $tables,
        private Transactions $transactions,
        private CommittedEvents $events,
        private AttributeWriter $writer,
        private AxisResolver $resolver,
        private BatchAttributeProcessor $batchAttributes,
        private Outbox $outbox,
        private Clock $clock,
    ) {}

    public function create(CreateVariant $command): VariantView
    {
        return $this->transactions->run(function () use ($command): VariantView {
            $db = $this->db();
            $product = $this->lockProduct($command->product, writable: true);
            $axes = $this->resolver->axes($db, $command->product->value);
            $values = AxisResolver::resolve($axes, $command->axes);
            $combination = AxisResolver::ordered($axes, $command->axes);

            $id = VariantId::generate();
            // Every attribute check runs before the first write: a refused value leaves no variant behind.
            $attributes = $this->writer->prepare(
                AttributeOwner::variant($id, $command->product),
                new ProductTypeId(Rows::string($product, 'product_type_id')),
                [],
                $command->attributes,
                [],
                creating: true,
            );
            $now = PgTimestamp::now();

            try {
                // A nested transaction is a savepoint: a unique violation must not poison the caller's transaction.
                $db->transaction(function () use ($db, $command, $id, $values, $now): void {
                    $db->table($this->tables->table('variants'))->insert([
                        'id' => $id->value,
                        'product_id' => $command->product->value,
                        'sku' => $command->sku,
                        'barcode' => $command->barcode,
                        'option_signature' => OptionSignature::of($values),
                        'position' => $command->position ?? $this->nextPosition($db, $command->product),
                        'tax_class' => $command->taxClass,
                        'weight_grams' => $command->weightGrams,
                        ...$this->dimensionColumns($command->dimensions),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $this->insertValues($db, $command->product, [$id->value => $values]);
                });
            } catch (UniqueConstraintViolationException $e) {
                throw $this->duplicate($e, $combination, $command->sku, $command->barcode);
            }

            $this->writer->apply($attributes);

            $view = $this->find($id);
            $this->events->dispatch(new VariantCreated($view));

            return $view;
        });
    }

    public function generate(GenerateVariants $command): GeneratedVariants
    {
        if ($command->dryRun) {
            $plan = $this->plan($this->db(), $command, lock: false);

            return new GeneratedVariants($plan->planned(), [], $plan->skipped(), dryRun: true);
        }

        return $this->transactions->run(function () use ($command): GeneratedVariants {
            $db = $this->db();
            $plan = $this->plan($db, $command, lock: true);
            $missing = $plan->missing();

            if ($missing === []) {
                return new GeneratedVariants($plan->planned(), [], $plan->skipped(), dryRun: false);
            }

            $variants = $this->tables->table('variants');
            $position = $this->nextPosition($db, $command->product);

            if ($position > PHP_INT_MAX - count($missing) + 1) {
                throw new InvalidCommand('position', 'generated positions would exceed int64');
            }

            $now = PgTimestamp::now();

            try {
                // One savepoint for the whole generation: a SKU collision undoes every variant of it.
                $inserted = $db->transaction(function () use ($db, $command, $variants, $missing, $position, $now): array {
                    $inserted = [];

                    foreach (array_chunk($missing, self::INSERT_CHUNK, preserve_keys: true) as $chunk) {
                        $rows = [];
                        $bindings = [];

                        foreach ($chunk as $signature => $planned) {
                            $rows[] = '(?, ?, ?, ?, ?, ?, ?)';
                            $bindings[] = $planned['id'];
                            $bindings[] = $command->product->value;
                            $bindings[] = $planned['variant']->sku;
                            $bindings[] = $signature;
                            $bindings[] = $position++;
                            $bindings[] = $now;
                            $bindings[] = $now;
                        }

                        $returned = $db->select(
                            'insert into '.$db->table($variants)->getGrammar()->wrapTable($variants)
                            .' (id, product_id, sku, option_signature, position, created_at, updated_at) values '.implode(', ', $rows)
                            .' on conflict (product_id, option_signature) do nothing returning id',
                            $bindings,
                        );

                        foreach ($returned as $row) {
                            if (is_object($row)) {
                                $inserted[] = Rows::string($row, 'id');
                            }
                        }
                    }

                    $values = [];

                    foreach ($missing as $planned) {
                        if (in_array($planned['id'], $inserted, true)) {
                            $values[$planned['id']] = $planned['values'];
                        }
                    }

                    $this->insertValues($db, $command->product, $values);

                    return $inserted;
                });
            } catch (UniqueConstraintViolationException $e) {
                throw $this->skuCollision($db, $e, array_map(static fn (array $planned): string => $planned['variant']->sku, array_values($missing)));
            }

            $created = $this->views(array_values($db->table($variants)->whereIn('id', $inserted)->orderBy('position')->get()->all()));

            foreach ($created as $view) {
                $this->events->dispatch(new VariantCreated($view));
            }

            $createdIds = array_map(static fn (VariantView $view): string => $view->id->value, $created);
            $skipped = $plan->skipped();

            // Only the safety net can leave a planned combination out; it existed after all.
            foreach ($missing as $planned) {
                if (! in_array($planned['id'], $createdIds, true)) {
                    $skipped[] = $planned['variant']->axes;
                }
            }

            return new GeneratedVariants($plan->planned(), $created, $skipped, dryRun: false);
        });
    }

    public function update(UpdateVariant $command): VariantView
    {
        return $this->transactions->run(function () use ($command): VariantView {
            $db = $this->db();
            [, $row] = $this->lockVariant($command->variant, writable: true);
            $changes = [];

            foreach (['sku' => $command->sku, 'barcode' => $command->barcode, 'tax_class' => $command->taxClass, 'weight_grams' => $command->weightGrams, 'position' => $command->position] as $column => $value) {
                if ($value !== null) {
                    $changes[$column] = $value;
                }
            }

            if ($command->dimensions instanceof Dimensions) {
                $changes = [...$changes, ...$this->dimensionColumns($command->dimensions)];
            }

            foreach ($command->clear as $field) {
                $changes = [...$changes, ...match ($field) {
                    'barcode' => ['barcode' => null],
                    'taxClass' => ['tax_class' => null],
                    'weightGrams' => ['weight_grams' => null],
                    default => $this->dimensionColumns(null),
                }];
            }

            $changes = array_filter(
                $changes,
                static fn (mixed $value, string $column): bool => $value !== (get_object_vars($row)[$column] ?? null),
                ARRAY_FILTER_USE_BOTH,
            );

            if ($changes === []) {
                return $this->find($command->variant);
            }

            try {
                $db->transaction(fn () => $db->table($this->tables->table('variants'))
                    ->where('id', $command->variant->value)
                    ->update($changes + ['updated_at' => PgTimestamp::now()]));
            } catch (UniqueConstraintViolationException $e) {
                throw $this->duplicate($e, [], (string) $command->sku, $command->barcode);
            }

            return $this->updated($command->variant);
        });
    }

    public function setAttributes(SetVariantAttributes $command): VariantView
    {
        return $this->transactions->run(function () use ($command): VariantView {
            [$product, $row] = $this->lockVariant($command->variant, writable: true);

            $this->writer->write(
                AttributeOwner::variant($command->variant, new ProductId(Rows::string($product, 'id'))),
                new ProductTypeId(Rows::string($product, 'product_type_id')),
                Documents::decode(Rows::string($row, 'attributes')),
                $command->values,
                $command->remove,
                creating: false,
            );

            return $this->updated($command->variant);
        });
    }

    public function archive(ArchiveVariant $command): VariantView
    {
        return $this->changeStatus($command->variant, VariantStatus::Archived);
    }

    public function restore(RestoreVariant $command): VariantView
    {
        return $this->changeStatus($command->variant, VariantStatus::Active);
    }

    public function find(VariantId $id): VariantView
    {
        $row = $this->db()->table($this->tables->table('variants'))->where('id', $id->value)->first();

        return $this->views([$row ?? throw new VariantNotFound($id->value)])[0];
    }

    public function findBySku(string $sku): VariantView
    {
        $row = $this->db()->table($this->tables->table('variants'))->where('sku', $sku)->first();

        return $this->views([$row ?? throw new VariantNotFound($sku)])[0];
    }

    public function upsertMany(UpsertVariants $command): UpsertVariantsResult
    {
        if ($command->items === []) {
            return new UpsertVariantsResult([], [], [], 0, 0, 0, 0);
        }

        return $this->transactions->run(function () use ($command): UpsertVariantsResult {
            $db = $this->db();
            $isPg = $db instanceof Connection && $db->getDriverName() === 'pgsql';
            $variantsTable = $this->tables->table('variants');

            // 1. Load & lock all referenced products in deterministic id order (CAT-01, deadlock-free).
            $productIds = array_values(array_unique(array_map(
                static fn (UpsertVariantItem $item): string => $item->product->value,
                $command->items,
            )));
            sort($productIds);

            $productsById = [];

            foreach (array_chunk($productIds, self::UPSERT_CHUNK) as $chunk) {
                $productQuery = $db->table($this->tables->table('products'))
                    ->whereIn('id', $chunk)
                    ->orderBy('id');

                if ($isPg) {
                    $productQuery->lock('for no key update');
                }

                foreach ($productQuery->get() as $row) {
                    $productsById[Rows::string($row, 'id')] = $row;
                }
            }

            foreach ($productIds as $productId) {
                $productRow = $productsById[$productId] ?? throw new ProductNotFound($productId);
                self::checked($productRow, writable: true);
            }

            // 2. Preload axes for all referenced products in 2 queries total.
            $axesByProduct = $this->resolver->axesForMany($db, $productIds);

            // 3. Load & lock existing variants by SKU in chunked queries.
            $skus = array_map(static fn (UpsertVariantItem $item): string => $item->sku, $command->items);
            $existingBySku = [];

            foreach (array_chunk($skus, self::UPSERT_CHUNK) as $chunk) {
                $existingQuery = $db->table($variantsTable)
                    ->whereIn('sku', $chunk)
                    ->orderBy('id');

                if ($isPg) {
                    $existingQuery->lock('for no key update');
                }

                foreach ($existingQuery->get() as $row) {
                    $existingBySku[Rows::string($row, 'sku')] = $row;
                }
            }

            // 4. Load existing option signatures and max positions per product in 2 queries total.
            $existingSignatures = [];
            $nextPositions = array_fill_keys($productIds, 0);

            foreach (array_chunk($productIds, self::UPSERT_CHUNK) as $chunk) {
                $sigRows = $db->table($variantsTable)
                    ->whereIn('product_id', $chunk)
                    ->select('id', 'product_id', 'option_signature')
                    ->get();

                foreach ($sigRows as $sigRow) {
                    $key = Rows::string($sigRow, 'product_id')."\0".Rows::string($sigRow, 'option_signature');
                    $existingSignatures[$key] = Rows::string($sigRow, 'id');
                }

                $posRows = $db->table($variantsTable)
                    ->whereIn('product_id', $chunk)
                    ->selectRaw('product_id, max(position) as max_pos')
                    ->groupBy('product_id')
                    ->get();

                foreach ($posRows as $posRow) {
                    $max = get_object_vars($posRow)['max_pos'] ?? null;
                    $nextPositions[Rows::string($posRow, 'product_id')] = is_numeric($max) ? (int) $max + 1 : 0;
                }
            }

            // 5. Validate all items (axes, combinations, positions, attributes) in memory BEFORE any write.
            $planned = [];
            $attrInputs = [];
            $createdIds = [];
            $updatedIds = [];
            $newOptionValues = [];
            $batchSignatures = [];

            foreach ($command->items as $item) {
                $productId = $item->product->value;
                $productRow = $productsById[$productId];
                $axes = $axesByProduct[$productId] ?? [];
                $resolvedValues = AxisResolver::resolve($axes, $item->axes);
                $combination = AxisResolver::ordered($axes, $item->axes);
                $signature = OptionSignature::of($resolvedValues);
                $sigKey = "{$productId}\0{$signature}";

                $existing = $existingBySku[$item->sku] ?? null;

                if ($existing !== null) {
                    if (Rows::string($existing, 'product_id') !== $productId) {
                        throw new DuplicateCode('Variant SKU', $item->sku);
                    }

                    if (Rows::string($existing, 'option_signature') !== $signature) {
                        throw new InvalidAxes("axes of existing variant '{$item->sku}' cannot be changed");
                    }

                    $id = Rows::string($existing, 'id');
                    $position = $item->position ?? Rows::int($existing, 'position');
                    $currentDoc = Documents::decode(Rows::string($existing, 'attributes'));
                    $creating = false;
                    $updatedIds[] = $id;
                } else {
                    if (isset($existingSignatures[$sigKey]) || isset($batchSignatures[$sigKey])) {
                        throw new DuplicateCombination($combination);
                    }

                    $batchSignatures[$sigKey] = true;
                    $id = Uuid7::generate();

                    if ($item->position !== null) {
                        $position = $item->position;
                    } else {
                        $nextPos = $nextPositions[$productId];

                        if ($nextPos === PHP_INT_MAX) {
                            throw new InvalidCommand('position', 'no larger int64 position is available');
                        }

                        $position = $nextPos;
                        $nextPositions[$productId] = $nextPos + 1;
                    }

                    $currentDoc = [];
                    $creating = true;
                    $createdIds[] = $id;

                    foreach ($resolvedValues as $optionId => $optionValueId) {
                        $newOptionValues[] = [
                            'variant_id' => $id,
                            'product_id' => $productId,
                            'option_id' => $optionId,
                            'option_value_id' => $optionValueId,
                        ];
                    }
                }

                $planned[] = [
                    'id' => $id,
                    'item' => $item,
                    'signature' => $signature,
                    'position' => $position,
                    'creating' => $creating,
                ];

                $attrInputs[] = [
                    'productId' => $productId,
                    'variantId' => $id,
                    'typeId' => Rows::string($productRow, 'product_type_id'),
                    'current' => $currentDoc,
                    'set' => $item->attributes,
                    'creating' => $creating,
                    'replace' => $item->replaceAttributes,
                ];
            }

            $prepared = $this->batchAttributes->prepareBatch($db, AttributeLevel::Variant, $attrInputs);
            $now = PgTimestamp::now($this->clock);
            $wrappedVariants = $db->table($variantsTable)->getGrammar()->wrapTable($variantsTable);
            $rowPlaceholder = $isPg
                ? '(?::uuid, ?::uuid, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?, ?::timestamptz, ?::timestamptz)'
                : '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

            // 6. Bulk INSERT ... ON CONFLICT (sku) DO UPDATE inside a savepoint so barcode collisions roll back cleanly.
            try {
                $db->transaction(function () use ($db, $wrappedVariants, $rowPlaceholder, $planned, $prepared, $now, $newOptionValues): void {
                    foreach (array_chunk($planned, self::UPSERT_CHUNK) as $chunk) {
                        $placeholders = [];
                        $bindings = [];

                        foreach ($chunk as $entry) {
                            $item = $entry['item'];
                            $id = $entry['id'];
                            $dims = $this->dimensionColumns($item->dimensions);

                            $placeholders[] = $rowPlaceholder;
                            $bindings[] = $id;
                            $bindings[] = $item->product->value;
                            $bindings[] = $item->sku;
                            $bindings[] = $item->barcode;
                            $bindings[] = $entry['signature'];
                            $bindings[] = $item->status->value;
                            $bindings[] = $entry['position'];
                            $bindings[] = Documents::encode($prepared['documents'][$id] ?? []);
                            $bindings[] = $item->taxClass;
                            $bindings[] = $item->weightGrams;
                            $bindings[] = $dims['length_mm'];
                            $bindings[] = $dims['width_mm'];
                            $bindings[] = $dims['height_mm'];
                            $bindings[] = $now;
                            $bindings[] = $now;
                        }

                        $db->select(
                            "insert into {$wrappedVariants}"
                            .' (id, product_id, sku, barcode, option_signature, status, position, attributes, tax_class, weight_grams, length_mm, width_mm, height_mm, created_at, updated_at)'
                            .' values '.implode(', ', $placeholders)
                            .' on conflict (sku) do update set'
                            .' barcode = excluded.barcode,'
                            .' status = excluded.status,'
                            .' position = excluded.position,'
                            .' attributes = excluded.attributes,'
                            .' tax_class = excluded.tax_class,'
                            .' weight_grams = excluded.weight_grams,'
                            .' length_mm = excluded.length_mm,'
                            .' width_mm = excluded.width_mm,'
                            .' height_mm = excluded.height_mm,'
                            .' updated_at = excluded.updated_at'
                            .' returning id',
                            $bindings,
                            useReadPdo: false,
                        );
                    }

                    // 7. Insert variant_option_values for newly created variants in chunks.
                    foreach (array_chunk($newOptionValues, self::UPSERT_CHUNK) as $chunk) {
                        $db->table($this->tables->table('variant_option_values'))->insert($chunk);
                    }
                });
            } catch (UniqueConstraintViolationException $e) {
                throw $this->batchCollision($db, $e, $command);
            }

            // 8. Grouped reindex of cmz_attribute_index for all upserted variants.
            $allVariantIds = array_map(static fn (array $entry): string => $entry['id'], $planned);
            $this->batchAttributes->reindexVariants($db, $allVariantIds, $prepared['facts']);

            // 9. Record compressed Outbox event(s).
            $batchId = Uuid7::generate();
            $occurredAt = PgTimestamp::current($this->clock);
            $outboxEventCount = 0;
            $createdSet = array_fill_keys($createdIds, true);
            $variantToProduct = [];

            foreach ($planned as $entry) {
                $variantToProduct[$entry['id']] = $entry['item']->product->value;
            }

            foreach (array_chunk($allVariantIds, self::OUTBOX_EVENT_CHUNK) as $chunkIds) {
                $chunkCreated = array_values(array_filter($chunkIds, static fn (string $id): bool => isset($createdSet[$id])));
                $chunkUpdated = array_values(array_filter($chunkIds, static fn (string $id): bool => ! isset($createdSet[$id])));
                $chunkProducts = array_values(array_unique(array_map(
                    static fn (string $id): string => $variantToProduct[$id],
                    $chunkIds,
                )));

                $this->outbox->record(IntegrationEvent::of(
                    type: 'catalog.variants_upserted',
                    aggregateRef: 'catalog:batch',
                    payload: [
                        'batch_id' => $batchId,
                        'product_ids' => $chunkProducts,
                        'variant_ids' => $chunkIds,
                        'created_ids' => $chunkCreated,
                        'updated_ids' => $chunkUpdated,
                        'count' => count($chunkIds),
                    ],
                    occurredAt: $occurredAt,
                ));
                $outboxEventCount++;
            }

            // 10. Hydrate VariantViews in batched queries and preserve input order.
            $rowsById = [];

            foreach (array_chunk($allVariantIds, self::UPSERT_CHUNK) as $chunk) {
                foreach ($db->table($variantsTable)->whereIn('id', $chunk)->get() as $row) {
                    $rowsById[Rows::string($row, 'id')] = $row;
                }
            }

            $orderedRows = [];

            foreach ($allVariantIds as $id) {
                if (isset($rowsById[$id])) {
                    $orderedRows[] = $rowsById[$id];
                }
            }

            $views = $this->views($orderedRows);
            $createdVariantIds = array_map(static fn (string $id): VariantId => new VariantId($id), $createdIds);
            $updatedVariantIds = array_map(static fn (string $id): VariantId => new VariantId($id), $updatedIds);
            $affectedProductIds = array_map(static fn (string $id): ProductId => new ProductId($id), $productIds);

            $this->events->dispatch(new VariantsUpserted(
                batchId: $batchId,
                productIds: $affectedProductIds,
                createdIds: $createdVariantIds,
                updatedIds: $updatedVariantIds,
                count: count($views),
            ));

            return new UpsertVariantsResult(
                variants: $views,
                createdIds: $createdVariantIds,
                updatedIds: $updatedVariantIds,
                createdCount: count($createdVariantIds),
                updatedCount: count($updatedVariantIds),
                indexedFactCount: count($prepared['facts']),
                outboxEventCount: $outboxEventCount,
            );
        });
    }

    public function list(ListVariants $query): Page
    {
        $db = $this->db();

        if (! $db->table($this->tables->table('products'))->where('id', $query->product->value)->exists()) {
            throw new ProductNotFound($query->product->value);
        }

        $builder = $db->table($this->tables->table('variants'))
            ->where('product_id', $query->product->value)
            ->orderBy('position')
            ->orderBy('id')
            ->limit($query->first + 1);

        if (! $query->includeArchived) {
            $builder->where('status', VariantStatus::Active->value);
        }

        if ($query->after instanceof Cursor) {
            [$position, $id] = self::position($query->after);
            $builder->whereRaw('(position, id) > (?, ?::uuid)', [$position, $id]);
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->first;
        $views = $this->views(array_slice($rows, 0, $query->first));
        $last = $views === [] ? null : $views[array_key_last($views)];

        return new Page($views, $more && $last instanceof VariantView ? Cursor::after("{$last->position}:{$last->id->value}") : null);
    }

    /**
     * Checks and plans a generation; locks the product unless it is a dry run. Writes nothing.
     */
    private function plan(ConnectionInterface $db, GenerateVariants $command, bool $lock): GenerationPlan
    {
        $product = $lock ? $this->lockProduct($command->product, writable: true) : $this->readProduct($command->product);
        $axes = $this->resolver->axes($db, $command->product->value);

        AxisResolver::assertSameAxes($axes, array_keys($command->axes));
        $lists = [];

        foreach ($axes as $axis) {
            $lists[$axis->code] = $command->axes[$axis->code];

            foreach ($lists[$axis->code] as $value) {
                AxisResolver::value($axis, $value);
            }
        }

        $pattern = new SkuPattern($command->skuPattern, $lists);
        $excluded = [];

        foreach ($command->except as $combination) {
            $signature = OptionSignature::of(AxisResolver::resolve($axes, $combination));
            $generated = array_all($lists, static fn (array $values, string $code): bool => in_array($combination[$code], $values, true));

            if ($generated) {
                $excluded[$signature] = true;
            }
        }

        $total = 1;

        foreach ($lists as $values) {
            $total = $total > intdiv(PHP_INT_MAX, count($values)) ? PHP_INT_MAX : $total * count($values);
        }

        $requested = $total === PHP_INT_MAX ? $total : $total - count($excluded);

        if ($requested > $this->catalog->maxGeneratedVariants) {
            throw new TooManyVariants($requested, $this->catalog->maxGeneratedVariants);
        }

        // Generated variants carry no attributes: a type with required active variant attributes refuses here.
        $this->writer->prepare(
            AttributeOwner::variant(VariantId::generate(), $command->product),
            new ProductTypeId(Rows::string($product, 'product_type_id')),
            [],
            [],
            [],
            creating: true,
        );

        $existing = array_flip(array_filter(
            $db->table($this->tables->table('variants'))->where('product_id', $command->product->value)->pluck('option_signature')->all(),
            is_string(...),
        ));
        $plan = new GenerationPlan;

        foreach (self::combinations($lists) as $combination) {
            $values = AxisResolver::resolve($axes, $combination);
            $signature = OptionSignature::of($values);

            if (! isset($excluded[$signature])) {
                $plan->add($signature, $values, new PlannedVariant($combination, $pattern->render($combination)), isset($existing[$signature]));
            }
        }

        return $plan;
    }

    /**
     * Cartesian product in axis order, values in the given order.
     *
     * @param  array<string, list<string>>  $lists  option code => value codes, in axis order
     * @return list<array<string, string>>
     */
    private static function combinations(array $lists): array
    {
        $combinations = [[]];

        foreach ($lists as $code => $values) {
            $next = [];

            foreach ($combinations as $combination) {
                foreach ($values as $value) {
                    $nextCombination = $combination;
                    $nextCombination[$code] = $value;
                    $next[] = $nextCombination;
                }
            }

            $combinations = $next;
        }

        return $combinations;
    }

    private function changeStatus(VariantId $id, VariantStatus $status): VariantView
    {
        return $this->transactions->run(function () use ($id, $status): VariantView {
            // Restoring brings a combination back into the catalog: not on an archived product.
            [, $row] = $this->lockVariant($id, writable: $status === VariantStatus::Active);

            if (Rows::string($row, 'status') === $status->value) {
                return $this->find($id);
            }

            $this->db()->table($this->tables->table('variants'))
                ->where('id', $id->value)
                ->update(['status' => $status->value, 'updated_at' => PgTimestamp::now()]);

            return $this->updated($id);
        });
    }

    /** `for no key update`, not `for update`: projection inserts take `for key share` on the product (FK check). */
    private function lockProduct(ProductId $id, bool $writable): object
    {
        $row = $this->db()->table($this->tables->table('products'))->where('id', $id->value)->lock('for no key update')->first();

        return self::checked($row ?? throw new ProductNotFound($id->value), $writable);
    }

    private function readProduct(ProductId $id): object
    {
        $row = $this->db()->table($this->tables->table('products'))->where('id', $id->value)->first();

        return self::checked($row ?? throw new ProductNotFound($id->value), writable: true);
    }

    private static function checked(object $product, bool $writable): object
    {
        if ($writable && Rows::string($product, 'status') === ProductStatus::Archived->value) {
            throw new ProductArchived(Rows::string($product, 'handle'));
        }

        return $product;
    }

    /**
     * Locks the variant's product, then the variant (the catalog lock order).
     *
     * @return array{object, object} product row, variant row
     */
    private function lockVariant(VariantId $id, bool $writable): array
    {
        $db = $this->db();
        $variants = $this->tables->table('variants');
        $product = $db->table($variants)->where('id', $id->value)->value('product_id');

        if (! is_string($product)) {
            throw new VariantNotFound($id->value);
        }

        $productRow = $this->lockProduct(new ProductId($product), $writable);
        $row = $db->table($variants)->where('id', $id->value)->lock('for no key update')->first();

        return [$productRow, $row ?? throw new VariantNotFound($id->value)];
    }

    private function nextPosition(ConnectionInterface $db, ProductId $product): int
    {
        $last = $db->table($this->tables->table('variants'))->where('product_id', $product->value)->max('position');

        if (is_numeric($last) && (int) $last === PHP_INT_MAX) {
            throw new InvalidCommand('position', 'no larger int64 position is available');
        }

        return is_numeric($last) ? (int) $last + 1 : 0;
    }

    /** @param  array<string, array<string, string>>  $variants  variant id => (option id => option value id) */
    private function insertValues(ConnectionInterface $db, ProductId $product, array $variants): void
    {
        $rows = [];

        foreach ($variants as $variant => $values) {
            foreach ($values as $option => $value) {
                $rows[] = ['variant_id' => $variant, 'product_id' => $product->value, 'option_id' => $option, 'option_value_id' => $value];
            }
        }

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $db->table($this->tables->table('variant_option_values'))->insert($chunk);
        }
    }

    /** @return array{length_mm: ?int, width_mm: ?int, height_mm: ?int} */
    private function dimensionColumns(?Dimensions $dimensions): array
    {
        return [
            'length_mm' => $dimensions?->lengthMm,
            'width_mm' => $dimensions?->widthMm,
            'height_mm' => $dimensions?->heightMm,
        ];
    }

    /** @param  array<string, string>  $combination */
    private function duplicate(UniqueConstraintViolationException $e, array $combination, string $sku, ?string $barcode): Throwable
    {
        $table = $this->tables->table('variants');
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, ConstraintNames::unique($table, ['product_id', 'option_signature'])) => new DuplicateCombination($combination),
            str_contains($message, ConstraintNames::unique($table, 'sku')) => new DuplicateCode('Variant SKU', $sku),
            str_contains($message, ConstraintNames::unique($table, 'barcode')) => new DuplicateCode('Variant barcode', (string) $barcode),
            default => $e,
        };
    }

    private function batchCollision(ConnectionInterface $db, UniqueConstraintViolationException $e, UpsertVariants $command): Throwable
    {
        $table = $this->tables->table('variants');
        $message = $e->getMessage();

        if (str_contains($message, ConstraintNames::unique($table, 'barcode')) || str_contains($message, 'barcode')) {
            $barcodes = array_values(array_filter(
                array_map(static fn (UpsertVariantItem $item): ?string => $item->barcode, $command->items),
                is_string(...),
            ));

            if ($barcodes !== []) {
                $taken = $db->table($table)->whereIn('barcode', array_slice($barcodes, 0, self::UPSERT_CHUNK))->orderBy('barcode')->value('barcode');

                return new DuplicateCode('Variant barcode', is_string($taken) ? $taken : $barcodes[0]);
            }
        }

        if (str_contains($message, ConstraintNames::unique($table, ['product_id', 'option_signature'])) || str_contains($message, 'option_signature')) {
            return new DuplicateCombination($command->items[0]->axes);
        }

        return $e;
    }

    /**
     * After the savepoint rolled back, the SKU that collided is the one another variant holds.
     *
     * @param  list<string>  $skus
     */
    private function skuCollision(ConnectionInterface $db, UniqueConstraintViolationException $e, array $skus): Throwable
    {
        if (! str_contains($e->getMessage(), ConstraintNames::unique($this->tables->table('variants'), 'sku'))) {
            return $e;
        }

        $taken = $db->table($this->tables->table('variants'))->whereIn('sku', $skus)->orderBy('sku')->value('sku');

        return new DuplicateCode('Variant SKU', is_string($taken) ? $taken : $skus[0]);
    }

    private function updated(VariantId $id): VariantView
    {
        $view = $this->find($id);
        $this->events->dispatch(new VariantUpdated($view));

        return $view;
    }

    /** @return array{int, string} position and id of a list cursor */
    private static function position(Cursor $cursor): array
    {
        $parts = explode(':', $cursor->position(), 2);
        $position = filter_var($parts[0], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if (count($parts) !== 2 || $position === false || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $parts[1]) !== 1) {
            throw new InvalidCommand('after', 'is not a valid cursor', 'common.invalid_cursor');
        }

        return [$position, $parts[1]];
    }

    /**
     * @param  list<object>  $rows
     * @return list<VariantView>
     */
    private function views(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $db = $this->db();
        $ids = array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows);
        $documents = [];
        $codes = [];

        foreach ($rows as $row) {
            $document = Documents::decode(Rows::string($row, 'attributes'));
            $documents[Rows::string($row, 'id')] = $document;
            array_push($codes, ...array_map(strval(...), array_keys($document)));
        }

        $retired = $this->retired($db, $codes);
        $axes = [];

        foreach (array_chunk($ids, self::UPSERT_CHUNK) as $chunk) {
            $axisRows = $db->table($this->tables->table('variant_option_values').' as v')
                ->join($this->tables->table('product_options').' as p', static function (JoinClause $join): void {
                    $join->on('p.product_id', '=', 'v.product_id')->on('p.option_id', '=', 'v.option_id');
                })
                ->join($this->tables->table('options').' as o', 'o.id', '=', 'v.option_id')
                ->join($this->tables->table('option_values').' as ov', 'ov.id', '=', 'v.option_value_id')
                ->whereIn('v.variant_id', $chunk)
                ->orderBy('p.position')
                ->select('v.variant_id', 'o.code as option', 'ov.code as value')
                ->get();

            foreach ($axisRows as $axis) {
                $axes[Rows::string($axis, 'variant_id')][Rows::string($axis, 'option')] = Rows::string($axis, 'value');
            }
        }

        return array_map(static function (object $row) use ($documents, $retired, $axes): VariantView {
            $id = Rows::string($row, 'id');
            $attributes = array_diff_key($documents[$id], $retired);
            ksort($attributes, SORT_STRING);
            $length = Rows::nullableInt($row, 'length_mm');
            $width = Rows::nullableInt($row, 'width_mm');
            $height = Rows::nullableInt($row, 'height_mm');

            return new VariantView(
                new VariantId($id),
                new ProductId(Rows::string($row, 'product_id')),
                Rows::string($row, 'sku'),
                Rows::nullableString($row, 'barcode'),
                $axes[$id] ?? [],
                VariantStatus::from(Rows::string($row, 'status')),
                Rows::int($row, 'position'),
                $attributes,
                Rows::nullableString($row, 'tax_class'),
                Rows::nullableInt($row, 'weight_grams'),
                $length === null || $width === null || $height === null ? null : new Dimensions($length, $width, $height),
                SellableRef::of('variant', $id),
                Rows::date($row, 'created_at'),
                Rows::date($row, 'updated_at'),
            );
        }, $rows);
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
