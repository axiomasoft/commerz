<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Internal;

use Commerz\Catalog\Attributes\Contracts\AttributeType;
use Commerz\Catalog\Attributes\Data\AttributeChoiceId;
use Commerz\Catalog\Attributes\Data\AttributeChoiceView;
use Commerz\Catalog\Attributes\Data\AttributeDefinition;
use Commerz\Catalog\Attributes\Data\AttributeId;
use Commerz\Catalog\Attributes\Enums\AttributeStatus;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotAllowed;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotWritable;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Commerz\Catalog\Attributes\Exceptions\MissingRequiredAttributes;
use Commerz\Catalog\Common\Internal\Documents;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\ProductTypes\Data\ProductTypeId;
use Commerz\Catalog\ProductTypes\Enums\AttributeLevel;
use Commerz\Catalog\Variants\Data\VariantId;
use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Contributions\Contributions;

/**
 * The only writer of the `attributes` documents of products and variants and of the `attribute_index` projection
 * (CAT-02). It runs inside the caller's transaction, after the caller locked the owner row; it opens no transaction.
 * Every check happens in `prepare()`, before anything is written, so a refused value leaves no partial change even
 * when the caller's transaction is the host's and the host goes on after the exception.
 *
 * For `overridable_variant` slots, effective values (variant local override or product fallback) materialize
 * synchronously per variant (`variant_id = V`) in `attribute_index`; changing a product's overridable attribute
 * reindexes only variants without a local override and leaves overridden variants untouched.
 */
#[Internal]
final readonly class AttributeWriter implements AttributeIndexer
{
    /** Owners read per reindex step (keyset by id). */
    private const int BATCH = 500;

    /** Projection rows per INSERT; 8 columns each keep a statement far below the 65535 bind limit. */
    private const int INSERT_CHUNK = 1000;

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Contributions $contributions,
    ) {}

    /**
     * @param  array<array-key, mixed>  $current  stored document of the owner
     * @param  array<array-key, mixed>  $set  code => input
     * @param  list<string>  $remove  codes
     * @return array<array-key, mixed> the new stored document (already written, projection rebuilt)
     */
    public function write(AttributeOwner $owner, ProductTypeId $type, array $current, array $set, array $remove, bool $creating): array
    {
        $prepared = $this->prepare($owner, $type, $current, $set, $remove, $creating);
        $this->apply($prepared);

        return $prepared->document;
    }

    /**
     * Validates the change, locks any affected variants (`id ASC`) then attribute definitions (`for share`), and
     * computes the document and its projection facts. Writes nothing; for a new owner it runs before the owner row
     * is inserted.
     *
     * @param  array<array-key, mixed>  $current  stored document of the owner
     * @param  array<array-key, mixed>  $set  code => input
     * @param  list<string>  $remove  codes
     */
    public function prepare(AttributeOwner $owner, ProductTypeId $type, array $current, array $set, array $remove, bool $creating): PreparedAttributes
    {
        $setCodes = array_map(strval(...), array_keys($set));
        $both = array_values(array_intersect($setCodes, $remove));

        if ($both !== []) {
            throw new InvalidCommand('attributes', "'{$both[0]}' is both set and removed", 'catalog.invalid_attributes');
        }

        foreach ($set as $code => $input) {
            if ($input === null) {
                throw new InvalidAttributeValue((string) $code, 'null is not a value; remove the attribute instead');
            }
        }

        $db = $this->db();
        $slots = $this->slots($db, $type, $owner);

        foreach ([...$setCodes, ...$remove] as $code) {
            if (! array_key_exists($code, $slots)) {
                throw new AttributeNotAllowed($code, $owner->level);
            }
        }

        $overridableCodes = [];
        $required = [];

        foreach ($slots as $code => $slot) {
            if ($slot['level'] === AttributeLevel::OverridableVariant) {
                $overridableCodes[] = $code;
            }

            if ($slot['required']) {
                $required[] = $code;
            }
        }

        $next = array_diff_key($current, array_flip($remove));
        $productDocument = [];

        if ($owner->variant !== null && $overridableCodes !== []) {
            $productRaw = $db->table($this->tables->table('products'))->where('id', $owner->product)->value('attributes');
            $productDocument = is_string($productRaw) ? Documents::decode($productRaw) : [];
        }

        $changedOverridable = [];
        $lockedVariants = [];

        if ($owner->variant === null && ! $creating && $overridableCodes !== []) {
            foreach ($overridableCodes as $code) {
                if (in_array($code, $setCodes, true) || (in_array($code, $remove, true) && array_key_exists($code, $current))) {
                    $changedOverridable[] = $code;
                }
            }

            if ($changedOverridable !== []) {
                // Catalog lock order: product -> variants by id ASC -> attribute definitions by id ASC.
                $lockedVariants = $db->table($this->tables->table('variants'))
                    ->where('product_id', $owner->product)
                    ->orderBy('id')
                    ->lock('for no key update')
                    ->select('id', 'attributes')
                    ->get()
                    ->all();
            }
        }

        $fallbackCodes = array_values(array_intersect(
            $overridableCodes,
            array_map(strval(...), array_keys($productDocument)),
        ));

        $definitions = $this->definitions($db, [
            ...array_map(strval(...), array_keys($next)),
            ...$setCodes,
            ...$remove,
            ...$required,
            ...$fallbackCodes,
        ]);

        foreach ($set as $code => $input) {
            $definition = $definitions[(string) $code];

            if ($definition->status !== AttributeStatus::Active) {
                throw new AttributeNotWritable((string) $code, $definition->status->value);
            }

            $next[$code] = $this->contributions->get(AttributeType::KIND, $definition->type, AttributeType::class)
                ->normalize($input, $definition->definition);
        }

        $missing = [];

        foreach ($required as $code) {
            $active = ($definitions[$code] ?? null)?->status === AttributeStatus::Active;

            if ($owner->variant !== null && $slots[$code]['level'] === AttributeLevel::OverridableVariant) {
                $hasFallback = array_key_exists($code, $productDocument);
                $absent = $creating
                    ? (! array_key_exists($code, $next) && ! $hasFallback)
                    : (in_array($code, $remove, true) && ! $hasFallback);
            } else {
                $absent = $creating ? ! array_key_exists($code, $next) : in_array($code, $remove, true);
            }

            if ($active && $absent) {
                $missing[] = $code;
            }
        }

        if ($missing !== []) {
            throw new MissingRequiredAttributes($missing);
        }

        if ($owner->variant !== null) {
            $effective = $next;

            foreach ($overridableCodes as $code) {
                if (! array_key_exists($code, $effective) && array_key_exists($code, $productDocument)) {
                    $effective[$code] = $productDocument[$code];
                }
            }

            $facts = [];

            foreach ($effective as $code => $value) {
                $definition = $definitions[(string) $code] ?? null;

                if ($definition instanceof StoredAttribute) {
                    array_push($facts, ...$this->facts($definition, $owner->product, $owner->variant, $value));
                }
            }

            return new PreparedAttributes($owner, $next, $facts);
        }

        $facts = [];

        foreach ($next as $code => $value) {
            $codeStr = (string) $code;
            $slot = $slots[$codeStr] ?? null;

            // Overridable variant attributes materialize per variant, never as `variant_id is null` rows.
            if ($slot !== null && $slot['level'] === AttributeLevel::OverridableVariant) {
                continue;
            }

            $definition = $definitions[$codeStr] ?? null;

            if ($definition instanceof StoredAttribute) {
                array_push($facts, ...$this->facts($definition, $owner->product, null, $value));
            }
        }

        $variantFallbacks = [];

        if ($changedOverridable !== [] && $lockedVariants !== []) {
            $decodedVariants = [];

            foreach ($lockedVariants as $variantRow) {
                $decodedVariants[Rows::string($variantRow, 'id')] = Documents::decode(Rows::string($variantRow, 'attributes'));
            }

            foreach ($changedOverridable as $code) {
                $definition = $definitions[$code] ?? null;

                if (! $definition instanceof StoredAttribute) {
                    continue;
                }

                $unoverriddenIds = [];
                $fallbackFacts = [];
                $hasProductValue = array_key_exists($code, $next);

                foreach ($decodedVariants as $variantId => $variantDocument) {
                    if (array_key_exists($code, $variantDocument)) {
                        // Variant carries a local override: leave its projection untouched.
                        continue;
                    }

                    $unoverriddenIds[] = $variantId;

                    if ($hasProductValue) {
                        array_push($fallbackFacts, ...$this->facts($definition, $owner->product, $variantId, $next[$code]));
                    }
                }

                if ($unoverriddenIds !== []) {
                    $variantFallbacks[] = [
                        'attributeId' => $definition->id,
                        'variantIds' => $unoverriddenIds,
                        'facts' => $fallbackFacts,
                    ];
                }
            }
        }

        return new PreparedAttributes($owner, $next, $facts, $variantFallbacks);
    }

    /** Writes a prepared change: the owner's projection rows, any unoverridden variant fallbacks, then its document. */
    public function apply(PreparedAttributes $prepared): void
    {
        $db = $this->db();
        $owner = $prepared->owner;
        $index = $this->tables->table('attribute_index');

        $projection = $db->table($index)->where('product_id', $owner->product);
        $owner->variant === null ? $projection->whereNull('variant_id') : $projection->where('variant_id', $owner->variant);
        $projection->delete();

        $this->insert($db, $prepared->facts);

        foreach ($prepared->variantFallbacks as $fallback) {
            $db->table($index)
                ->where('product_id', $owner->product)
                ->where('attribute_id', $fallback['attributeId'])
                ->whereIn('variant_id', $fallback['variantIds'])
                ->delete();

            $this->insert($db, $fallback['facts']);
        }

        $db->table($this->tables->table($owner->table()))
            ->where('id', $owner->id())
            ->update(['attributes' => Documents::encode($prepared->document), 'updated_at' => PgTimestamp::now()]);
    }

    /** Rebuilds all projection facts of one product and all of its variants (respecting local overrides). */
    public function reindexProduct(ProductId $product): void
    {
        $db = $this->db();
        $productRow = $db->table($this->tables->table('products'))->where('id', $product->value)->first();

        if ($productRow === null) {
            return;
        }

        $typeId = new ProductTypeId(Rows::string($productRow, 'product_type_id'));
        $productDoc = Documents::decode(Rows::string($productRow, 'attributes'));
        $variantRows = $db->table($this->tables->table('variants'))
            ->where('product_id', $product->value)
            ->orderBy('id')
            ->select('id', 'attributes')
            ->get()
            ->all();

        $slotRows = $db->table($this->tables->table('product_type_attributes').' as s')
            ->join($this->tables->table('attributes').' as a', 'a.id', '=', 's.attribute_id')
            ->where('s.product_type_id', $typeId->value)
            ->select('a.code', 's.level')
            ->get();

        $levels = [];

        foreach ($slotRows as $slotRow) {
            $levels[Rows::string($slotRow, 'code')] = AttributeLevel::from(Rows::string($slotRow, 'level'));
        }

        $allCodes = array_map(strval(...), array_keys($productDoc));
        $variantDocs = [];

        foreach ($variantRows as $variantRow) {
            $doc = Documents::decode(Rows::string($variantRow, 'attributes'));
            $variantDocs[Rows::string($variantRow, 'id')] = $doc;
            array_push($allCodes, ...array_map(strval(...), array_keys($doc)));
        }

        $definitions = $this->read($allCodes);
        $facts = [];

        foreach ($productDoc as $code => $value) {
            $codeStr = (string) $code;

            if (($levels[$codeStr] ?? AttributeLevel::Product) === AttributeLevel::OverridableVariant) {
                continue;
            }

            $definition = $definitions[$codeStr] ?? null;

            if ($definition instanceof StoredAttribute) {
                array_push($facts, ...$this->facts($definition, $product->value, null, $value));
            }
        }

        $overridableCodes = array_keys(array_filter(
            $levels,
            static fn (AttributeLevel $level): bool => $level === AttributeLevel::OverridableVariant,
        ));

        foreach ($variantDocs as $variantId => $variantDoc) {
            $effective = $variantDoc;

            foreach ($overridableCodes as $code) {
                if (! array_key_exists($code, $effective) && array_key_exists($code, $productDoc)) {
                    $effective[$code] = $productDoc[$code];
                }
            }

            foreach ($effective as $code => $value) {
                $definition = $definitions[(string) $code] ?? null;

                if ($definition instanceof StoredAttribute) {
                    array_push($facts, ...$this->facts($definition, $product->value, $variantId, $value));
                }
            }
        }

        $db->table($this->tables->table('attribute_index'))->where('product_id', $product->value)->delete();
        $this->insert($db, $facts);
    }

    /** Rebuilds all projection facts of a single variant (local override or product fallback). */
    public function reindexVariant(VariantId $variant): void
    {
        $db = $this->db();
        $variantRow = $db->table($this->tables->table('variants').' as v')
            ->join($this->tables->table('products').' as p', 'p.id', '=', 'v.product_id')
            ->where('v.id', $variant->value)
            ->select('v.id', 'v.product_id', 'v.attributes as variant_attributes', 'p.product_type_id', 'p.attributes as product_attributes')
            ->first();

        if ($variantRow === null) {
            return;
        }

        $productId = Rows::string($variantRow, 'product_id');
        $typeId = Rows::string($variantRow, 'product_type_id');
        $variantDoc = Documents::decode(Rows::string($variantRow, 'variant_attributes'));
        $productDoc = Documents::decode(Rows::string($variantRow, 'product_attributes'));

        $overridableRows = $db->table($this->tables->table('product_type_attributes').' as s')
            ->join($this->tables->table('attributes').' as a', 'a.id', '=', 's.attribute_id')
            ->where('s.product_type_id', $typeId)
            ->where('s.level', AttributeLevel::OverridableVariant->value)
            ->pluck('a.code')
            ->all();

        $effective = $variantDoc;

        foreach ($overridableRows as $code) {
            if (is_string($code) && ! array_key_exists($code, $effective) && array_key_exists($code, $productDoc)) {
                $effective[$code] = $productDoc[$code];
            }
        }

        $definitions = $this->read(array_map(strval(...), array_keys($effective)));
        $facts = [];

        foreach ($effective as $code => $value) {
            $definition = $definitions[(string) $code] ?? null;

            if ($definition instanceof StoredAttribute) {
                array_push($facts, ...$this->facts($definition, $productId, $variant->value, $value));
            }
        }

        $db->table($this->tables->table('attribute_index'))
            ->where('product_id', $productId)
            ->where('variant_id', $variant->value)
            ->delete();

        $this->insert($db, $facts);
    }

    /** Rebuilds all facts of one attribute; the caller holds its definition row `for no key update`. */
    public function reindexAttribute(AttributeId $attribute): void
    {
        $db = $this->db();
        $index = $this->tables->table('attribute_index');

        $db->table($index)->where('attribute_id', $attribute->value)->delete();

        $row = $db->table($this->tables->table('attributes'))->where('id', $attribute->value)->first();

        if ($row === null) {
            return;
        }

        $code = Rows::string($row, 'code');
        $definition = $this->stored($db, [$row])[$code];

        if (! $definition->projected() || ! $this->contributions->has(AttributeType::KIND, $definition->type)) {
            return;
        }

        $slots = $db->table($this->tables->table('product_type_attributes'))
            ->where('attribute_id', $attribute->value)
            ->select('product_type_id', 'level')
            ->get();

        $productTypes = [];
        $variantTypes = [];
        $overridableTypes = [];

        foreach ($slots as $slot) {
            $typeId = Rows::string($slot, 'product_type_id');
            match (AttributeLevel::from(Rows::string($slot, 'level'))) {
                AttributeLevel::Product => $productTypes[] = $typeId,
                AttributeLevel::Variant => $variantTypes[] = $typeId,
                AttributeLevel::OverridableVariant => $overridableTypes[] = $typeId,
            };
        }

        if ($productTypes !== []) {
            $this->reindexProductSlots($db, $definition, $code, $productTypes);
        }

        if ($variantTypes !== []) {
            $this->reindexVariantSlots($db, $definition, $code, $variantTypes);
        }

        if ($overridableTypes !== []) {
            $this->reindexOverridableSlots($db, $definition, $code, $overridableTypes);
        }
    }

    /**
     * Definitions of the codes as committed now, without a lock: for readers such as the filter compiler.
     *
     * @param  list<string>  $codes
     * @return array<string, StoredAttribute> code => definition
     */
    public function read(array $codes): array
    {
        $codes = array_values(array_unique($codes));

        if ($codes === []) {
            return [];
        }

        $db = $this->db();

        return $this->stored($db, $db->table($this->tables->table('attributes'))->whereIn('code', $codes)->get()->all());
    }

    /**
     * @param  list<string>  $productTypes
     */
    private function reindexProductSlots(ConnectionInterface $db, StoredAttribute $definition, string $code, array $productTypes): void
    {
        $after = null;

        do {
            $query = $db->table($this->tables->table('products'))
                ->whereIn('product_type_id', $productTypes)
                ->orderBy('id')
                ->limit(self::BATCH)
                ->select(['id', 'attributes']);

            if ($db instanceof Connection && $db->getDriverName() === 'pgsql') {
                $query->whereRaw('jsonb_exists(attributes, ?)', [$code]);
            }

            if ($after !== null) {
                $query->where('id', '>', $after);
            }

            $owners = $query->get()->all();
            $facts = [];

            foreach ($owners as $owner) {
                $document = Documents::decode(Rows::string($owner, 'attributes'));
                $id = Rows::string($owner, 'id');

                if (array_key_exists($code, $document)) {
                    array_push($facts, ...$this->facts($definition, $id, null, $document[$code]));
                }

                $after = $id;
            }

            $this->insert($db, $facts);
        } while (count($owners) === self::BATCH);
    }

    /**
     * @param  list<string>  $variantTypes
     */
    private function reindexVariantSlots(ConnectionInterface $db, StoredAttribute $definition, string $code, array $variantTypes): void
    {
        $after = null;

        do {
            $query = $db->table($this->tables->table('variants').' as v')
                ->join($this->tables->table('products').' as p', 'p.id', '=', 'v.product_id')
                ->whereIn('p.product_type_id', $variantTypes)
                ->orderBy('v.id')
                ->limit(self::BATCH)
                ->select(['v.id', 'v.product_id', 'v.attributes']);

            if ($db instanceof Connection && $db->getDriverName() === 'pgsql') {
                $query->whereRaw('jsonb_exists(v.attributes, ?)', [$code]);
            }

            if ($after !== null) {
                $query->where('v.id', '>', $after);
            }

            $owners = $query->get()->all();
            $facts = [];

            foreach ($owners as $owner) {
                $document = Documents::decode(Rows::string($owner, 'attributes'));
                $id = Rows::string($owner, 'id');

                if (array_key_exists($code, $document)) {
                    array_push($facts, ...$this->facts($definition, Rows::string($owner, 'product_id'), $id, $document[$code]));
                }

                $after = $id;
            }

            $this->insert($db, $facts);
        } while (count($owners) === self::BATCH);
    }

    /**
     * @param  list<string>  $overridableTypes
     */
    private function reindexOverridableSlots(ConnectionInterface $db, StoredAttribute $definition, string $code, array $overridableTypes): void
    {
        $after = null;

        do {
            $query = $db->table($this->tables->table('variants').' as v')
                ->join($this->tables->table('products').' as p', 'p.id', '=', 'v.product_id')
                ->whereIn('p.product_type_id', $overridableTypes)
                ->orderBy('v.id')
                ->limit(self::BATCH)
                ->select(['v.id', 'v.product_id', 'v.attributes as variant_attributes', 'p.attributes as product_attributes']);

            if ($db instanceof Connection && $db->getDriverName() === 'pgsql') {
                $query->where(static function (Builder $has) use ($code): void {
                    $has->whereRaw('jsonb_exists(v.attributes, ?)', [$code])
                        ->orWhereRaw('jsonb_exists(p.attributes, ?)', [$code]);
                });
            }

            if ($after !== null) {
                $query->where('v.id', '>', $after);
            }

            $owners = $query->get()->all();
            $facts = [];

            foreach ($owners as $owner) {
                $variantDoc = Documents::decode(Rows::string($owner, 'variant_attributes'));
                $productDoc = Documents::decode(Rows::string($owner, 'product_attributes'));
                $id = Rows::string($owner, 'id');
                $productId = Rows::string($owner, 'product_id');

                if (array_key_exists($code, $variantDoc)) {
                    array_push($facts, ...$this->facts($definition, $productId, $id, $variantDoc[$code]));
                } elseif (array_key_exists($code, $productDoc)) {
                    array_push($facts, ...$this->facts($definition, $productId, $id, $productDoc[$code]));
                }

                $after = $id;
            }

            $this->insert($db, $facts);
        } while (count($owners) === self::BATCH);
    }

    /**
     * Attribute slots of the type allowed on the owner (including `overridable_variant` for both product and variant).
     *
     * @return array<string, array{level: AttributeLevel, required: bool}> code => slot metadata
     */
    private function slots(ConnectionInterface $db, ProductTypeId $type, AttributeOwner $owner): array
    {
        $levels = $owner->variant === null
            ? [AttributeLevel::Product->value, AttributeLevel::OverridableVariant->value]
            : [AttributeLevel::Variant->value, AttributeLevel::OverridableVariant->value];

        $rows = $db->table($this->tables->table('product_type_attributes').' as s')
            ->join($this->tables->table('attributes').' as a', 'a.id', '=', 's.attribute_id')
            ->where('s.product_type_id', $type->value)
            ->whereIn('s.level', $levels)
            ->select('a.code', 's.level', 's.required')
            ->get();

        $slots = [];

        foreach ($rows as $row) {
            $slots[Rows::string($row, 'code')] = [
                'level' => AttributeLevel::from(Rows::string($row, 'level')),
                'required' => Rows::bool($row, 'required'),
            ];
        }

        return $slots;
    }

    /**
     * Definitions of the codes, locked `for share` in id order so a concurrent definition change waits for this write.
     *
     * @param  list<string>  $codes
     * @return array<string, StoredAttribute> code => definition
     */
    private function definitions(ConnectionInterface $db, array $codes): array
    {
        $codes = array_values(array_unique($codes));

        if ($codes === []) {
            return [];
        }

        $rows = $db->table($this->tables->table('attributes'))
            ->whereIn('code', $codes)
            ->orderBy('id')
            ->lock('for share')
            ->get()
            ->all();

        return $this->stored($db, $rows);
    }

    /**
     * @param  array<object>  $rows  attribute rows
     * @return array<string, StoredAttribute> code => definition
     */
    private function stored(ConnectionInterface $db, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $choices = [];

        $choiceRows = $db->table($this->tables->table('attribute_choices'))
            ->whereIn('attribute_id', array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows))
            ->orderBy('position')
            ->orderBy('code')
            ->get();

        foreach ($choiceRows as $choice) {
            $choices[Rows::string($choice, 'attribute_id')][] = new AttributeChoiceView(
                new AttributeChoiceId(Rows::string($choice, 'id')),
                Rows::string($choice, 'code'),
                Rows::localized($choice, 'label'),
                Rows::int($choice, 'position'),
            );
        }

        $stored = [];

        foreach ($rows as $row) {
            $id = Rows::string($row, 'id');
            $code = Rows::string($row, 'code');
            $config = [];

            foreach (Rows::json($row, 'config') as $key => $value) {
                $config[(string) $key] = $value;
            }

            $stored[$code] = new StoredAttribute(
                $id,
                Rows::string($row, 'type'),
                AttributeStatus::from(Rows::string($row, 'status')),
                Rows::bool($row, 'filterable'),
                new AttributeDefinition($code, $config, $choices[$id] ?? []),
            );
        }

        return $stored;
    }

    /**
     * Projection rows of one stored value; none when the attribute is not projected or its type is not installed.
     *
     * @return list<array<string, string|bool|null>>
     */
    private function facts(StoredAttribute $attribute, string $product, ?string $variant, mixed $value): array
    {
        if (! $attribute->projected() || ! $this->contributions->has(AttributeType::KIND, $attribute->type)) {
            return [];
        }

        if (! is_string($value) && ! is_int($value) && ! is_bool($value) && ! is_array($value)) {
            return [];
        }

        $type = $this->contributions->get(AttributeType::KIND, $attribute->type, AttributeType::class);
        $rows = [];

        foreach ($type->index($value, $attribute->definition) as $fact) {
            $rows[] = [
                'product_id' => $product,
                'variant_id' => $variant,
                'attribute_id' => $attribute->id,
                'choice_id' => $fact->choice?->value,
                'num' => $fact->number,
                'flag' => $fact->flag,
                'day' => $fact->day,
                'term' => $fact->term,
            ];
        }

        return $rows;
    }

    /** @param  list<array<string, string|bool|null>>  $facts */
    private function insert(ConnectionInterface $db, array $facts): void
    {
        foreach (array_chunk($facts, self::INSERT_CHUNK) as $chunk) {
            $db->table($this->tables->table('attribute_index'))->insert($chunk);
        }
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
