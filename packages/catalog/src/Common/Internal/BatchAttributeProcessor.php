<?php

declare(strict_types=1);

namespace Commerz\Catalog\Common\Internal;

use Commerz\Catalog\Attributes\Contracts\AttributeType;
use Commerz\Catalog\Attributes\Data\AttributeChoiceId;
use Commerz\Catalog\Attributes\Data\AttributeChoiceView;
use Commerz\Catalog\Attributes\Data\AttributeDefinition;
use Commerz\Catalog\Attributes\Enums\AttributeStatus;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotAllowed;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotWritable;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Commerz\Catalog\Attributes\Exceptions\MissingRequiredAttributes;
use Commerz\Catalog\Attributes\Internal\StoredAttribute;
use Commerz\Catalog\ProductTypes\Enums\AttributeLevel;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Contributions\Contributions;

/**
 * Batch attribute validator, normalizer, and grouped `cmz_attribute_index` reindexer for `upsertMany()`.
 *
 * Preloads all type slots, attribute definitions, and choices in O(1) SQL queries regardless of batch size (e.g.
 * 1,000+ products or variants), validates every item before any write, and reindexes `cmz_attribute_index` using
 * chunked `DELETE ... WHERE IN` and bulk `INSERT` statements.
 */
#[Internal]
final readonly class BatchAttributeProcessor
{
    /** Rows per bulk INSERT or WHERE IN chunk; keeps bind parameters well under PG (65,535) and SQLite limits. */
    public const int CHUNK_SIZE = 500;

    public function __construct(
        private TableNames $tables,
        private Contributions $contributions,
    ) {}

    /**
     * Validates and prepares attribute documents and `cmz_attribute_index` fact rows for a batch of owners.
     *
     * @param  list<array{productId: string, variantId: ?string, typeId: string, current: array<array-key, mixed>, set: array<array-key, mixed>, creating: bool, replace: bool}>  $items
     * @return array{documents: array<string, array<array-key, mixed>>, facts: list<array<string, string|bool|null>>}
     */
    public function prepareBatch(ConnectionInterface $db, AttributeLevel $level, array $items): array
    {
        if ($items === []) {
            return ['documents' => [], 'facts' => []];
        }

        $typeIds = [];
        $candidateCodes = [];

        foreach ($items as $item) {
            $typeIds[$item['typeId']] = true;

            foreach ($item['set'] as $code => $input) {
                if ($input === null) {
                    throw new InvalidAttributeValue((string) $code, 'null is not a value; omit or clear the attribute instead');
                }

                $candidateCodes[(string) $code] = true;
            }

            if (! $item['replace']) {
                foreach (array_keys($item['current']) as $code) {
                    $candidateCodes[(string) $code] = true;
                }
            }
        }

        $slotsByType = $this->loadSlots($db, array_keys($typeIds), $level);

        foreach ($slotsByType as $slots) {
            foreach ($slots as $code => $required) {
                if ($required) {
                    $candidateCodes[$code] = true;
                }
            }
        }

        $definitions = $this->loadDefinitions($db, array_keys($candidateCodes));
        $documents = [];
        $facts = [];

        foreach ($items as $item) {
            $ownerId = $item['variantId'] ?? $item['productId'];
            $slots = $slotsByType[$item['typeId']] ?? [];
            $next = $item['replace'] ? [] : $item['current'];

            foreach ($item['set'] as $code => $input) {
                $codeStr = (string) $code;

                if (! array_key_exists($codeStr, $slots)) {
                    throw new AttributeNotAllowed($codeStr, $level);
                }

                $definition = $definitions[$codeStr] ?? null;

                if (! $definition instanceof StoredAttribute) {
                    throw new AttributeNotAllowed($codeStr, $level);
                }

                if ($definition->status !== AttributeStatus::Active) {
                    throw new AttributeNotWritable($codeStr, $definition->status->value);
                }

                $next[$codeStr] = $this->contributions->get(AttributeType::KIND, $definition->type, AttributeType::class)
                    ->normalize($input, $definition->definition);
            }

            $missing = [];

            foreach ($slots as $code => $required) {
                if (! $required) {
                    continue;
                }

                $active = ($definitions[$code] ?? null)?->status === AttributeStatus::Active;

                if ($active && ($item['creating'] || $item['replace']) && ! array_key_exists($code, $next)) {
                    $missing[] = $code;
                }
            }

            if ($missing !== []) {
                throw new MissingRequiredAttributes($missing);
            }

            $documents[$ownerId] = $next;

            foreach ($next as $code => $value) {
                $definition = $definitions[(string) $code] ?? null;

                if ($definition instanceof StoredAttribute) {
                    array_push($facts, ...$this->factsFor($definition, $item['productId'], $item['variantId'], $value));
                }
            }
        }

        return ['documents' => $documents, 'facts' => $facts];
    }

    /**
     * Rebuilds product-level `cmz_attribute_index` rows for all given products in grouped SQL statements.
     *
     * @param  list<string>  $productIds
     * @param  list<array<string, string|bool|null>>  $facts
     */
    public function reindexProducts(ConnectionInterface $db, array $productIds, array $facts): void
    {
        $indexTable = $this->tables->table('attribute_index');

        foreach (array_chunk(array_values(array_unique($productIds)), self::CHUNK_SIZE) as $chunk) {
            $db->table($indexTable)
                ->whereNull('variant_id')
                ->whereIn('product_id', $chunk)
                ->delete();
        }

        $this->insertFacts($db, $facts);
    }

    /**
     * Rebuilds variant-level `cmz_attribute_index` rows for all given variants in grouped SQL statements.
     *
     * @param  list<string>  $variantIds
     * @param  list<array<string, string|bool|null>>  $facts
     */
    public function reindexVariants(ConnectionInterface $db, array $variantIds, array $facts): void
    {
        $indexTable = $this->tables->table('attribute_index');

        foreach (array_chunk(array_values(array_unique($variantIds)), self::CHUNK_SIZE) as $chunk) {
            $db->table($indexTable)
                ->whereIn('variant_id', $chunk)
                ->delete();
        }

        $this->insertFacts($db, $facts);
    }

    /**
     * @param  list<string>  $typeIds
     * @return array<string, array<string, bool>> product_type_id => (code => required)
     */
    private function loadSlots(ConnectionInterface $db, array $typeIds, AttributeLevel $level): array
    {
        if ($typeIds === []) {
            return [];
        }

        $slots = [];

        foreach (array_chunk($typeIds, self::CHUNK_SIZE) as $chunk) {
            $rows = $db->table($this->tables->table('product_type_attributes').' as s')
                ->join($this->tables->table('attributes').' as a', 'a.id', '=', 's.attribute_id')
                ->whereIn('s.product_type_id', $chunk)
                ->where('s.level', $level->value)
                ->select('s.product_type_id', 'a.code', 's.required')
                ->get();

            foreach ($rows as $row) {
                $slots[Rows::string($row, 'product_type_id')][Rows::string($row, 'code')] = self::boolColumn($row, 'required');
            }
        }

        return $slots;
    }

    /**
     * Reads committed attribute definitions and choices without locking (used by `PgCatalogSearch`).
     *
     * @param  list<string>  $codes
     * @return array<string, StoredAttribute> code => definition
     */
    public function readDefinitions(ConnectionInterface $db, array $codes): array
    {
        return $this->loadDefinitions($db, $codes, lock: false);
    }

    /**
     * Loads and optionally locks attribute definitions (`for share` on PG) and their choices in 2 queries.
     *
     * @param  list<string>  $codes
     * @return array<string, StoredAttribute> code => definition
     */
    private function loadDefinitions(ConnectionInterface $db, array $codes, bool $lock = true): array
    {
        $codes = array_values(array_unique($codes));

        if ($codes === []) {
            return [];
        }

        $isPg = $db instanceof Connection && $db->getDriverName() === 'pgsql';
        $rows = [];

        foreach (array_chunk($codes, self::CHUNK_SIZE) as $chunk) {
            $query = $db->table($this->tables->table('attributes'))
                ->whereIn('code', $chunk)
                ->orderBy('id');

            if ($lock && $isPg) {
                $query->lock('for share');
            }

            array_push($rows, ...array_values(array_filter($query->get()->all(), is_object(...))));
        }

        if ($rows === []) {
            return [];
        }

        $attributeIds = array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows);
        $choices = [];

        foreach (array_chunk($attributeIds, self::CHUNK_SIZE) as $chunk) {
            $choiceRows = $db->table($this->tables->table('attribute_choices'))
                ->whereIn('attribute_id', $chunk)
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
                self::boolColumn($row, 'filterable'),
                new AttributeDefinition($code, $config, $choices[$id] ?? []),
            );
        }

        return $stored;
    }

    private static function boolColumn(object $row, string $column): bool
    {
        $value = get_object_vars($row)[$column] ?? null;

        return match (true) {
            is_bool($value) => $value,
            is_int($value) => $value !== 0,
            is_string($value) => in_array(strtolower($value), ['1', 't', 'true'], true),
            default => Rows::bool($row, $column),
        };
    }

    /** @return list<array<string, string|bool|null>> */
    private function factsFor(StoredAttribute $attribute, string $productId, ?string $variantId, mixed $value): array
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
                'product_id' => $productId,
                'variant_id' => $variantId,
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
    private function insertFacts(ConnectionInterface $db, array $facts): void
    {
        $indexTable = $this->tables->table('attribute_index');

        foreach (array_chunk($facts, self::CHUNK_SIZE) as $chunk) {
            $db->table($indexTable)->insert($chunk);
        }
    }
}
