<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Internal;

use Commerz\Catalog\Attributes\Enums\AttributeStatus;
use Commerz\Catalog\Attributes\Internal\AttributeOwner;
use Commerz\Catalog\Attributes\Internal\AttributeWriter;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Common\Internal\CatalogConfig;
use Commerz\Catalog\Common\Internal\Clock;
use Commerz\Catalog\Common\Internal\Documents;
use Commerz\Catalog\Common\Internal\Rows;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\Products\Exceptions\ProductArchived;
use Commerz\Catalog\Products\Exceptions\ProductNotFound;
use Commerz\Catalog\ProductTypes\Data\ProductTypeId;
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
use Commerz\Catalog\Variants\Data\VariantId;
use Commerz\Catalog\Variants\Data\VariantView;
use Commerz\Catalog\Variants\Enums\VariantStatus;
use Commerz\Catalog\Variants\Events\VariantCreated;
use Commerz\Catalog\Variants\Events\VariantUpdated;
use Commerz\Catalog\Variants\Exceptions\DuplicateCombination;
use Commerz\Catalog\Variants\Exceptions\TooManyVariants;
use Commerz\Catalog\Variants\Exceptions\VariantNotFound;
use Commerz\Configuration\CommerzConfig;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Schema\ConstraintNames;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * CAT-01: every change of a product's set of variants starts by locking the product row `for no key update`, so
 * concurrent creations and generations of one product run one after another and see each other's combinations.
 * `UNIQUE(product_id, option_signature)` and `on conflict do nothing` are the safety net, not the mechanism.
 */
final readonly class DatabaseVariants implements Variants
{
    /** Variant rows per generated INSERT; 7 bindings each stay far below the 65535 bind limit. */
    private const int INSERT_CHUNK = 1000;

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzConfig $config,
        private CatalogConfig $catalog,
        private TableNames $tables,
        private Transactions $transactions,
        private CommittedEvents $events,
        private AttributeWriter $writer,
        private AxisResolver $resolver,
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
            $now = Clock::now();

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

            $now = Clock::now();

            try {
                // One savepoint for the whole generation: a SKU collision undoes every variant of it.
                $inserted = $db->transaction(function () use ($db, $command, $variants, $missing, $position, $now): array {
                    $inserted = [];

                    foreach (array_chunk($missing, self::INSERT_CHUNK, preserve_keys: true) as $chunk) {
                        $rows = [];
                        $bindings = [];

                        foreach ($chunk as $signature => $planned) {
                            $rows[] = '(?, ?, ?, ?, ?, ?, ?)';
                            array_push($bindings, $planned['id'], $command->product->value, $planned['variant']->sku, $signature, $position++, $now, $now);
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
                    ->update($changes + ['updated_at' => Clock::now()]));
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
                ->update(['status' => $status->value, 'updated_at' => Clock::now()]);

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

        $axisRows = $db->table($this->tables->table('variant_option_values').' as v')
            ->join($this->tables->table('product_options').' as p', static function (JoinClause $join): void {
                $join->on('p.product_id', '=', 'v.product_id')->on('p.option_id', '=', 'v.option_id');
            })
            ->join($this->tables->table('options').' as o', 'o.id', '=', 'v.option_id')
            ->join($this->tables->table('option_values').' as ov', 'ov.id', '=', 'v.option_value_id')
            ->whereIn('v.variant_id', $ids)
            ->orderBy('p.position')
            ->select('v.variant_id', 'o.code as option', 'ov.code as value')
            ->get();

        foreach ($axisRows as $axis) {
            $axes[Rows::string($axis, 'variant_id')][Rows::string($axis, 'option')] = Rows::string($axis, 'value');
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
