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
use Commerz\Catalog\ProductTypes\Data\ProductTypeId;
use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Contributions\Contributions;

/**
 * The only writer of the `attributes` documents of products and variants and of the `attribute_index` projection
 * (CAT-02). It runs inside the caller's transaction, after the caller locked the owner row; it opens no transaction.
 * Every check happens in `prepare()`, before anything is written, so a refused value leaves no partial change even
 * when the caller's transaction is the host's and the host goes on after the exception.
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
     * Validates the change, locks the definitions it reads `for share` and computes the document and its facts.
     * Writes nothing; for a new owner it runs before the owner row is inserted.
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

        $next = array_diff_key($current, array_flip($remove));
        $required = array_map(strval(...), array_keys(array_filter($slots)));
        $definitions = $this->definitions($db, [...array_map(strval(...), array_keys($next)), ...$setCodes, ...$remove, ...$required]);

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
            $absent = $creating ? ! array_key_exists($code, $next) : in_array($code, $remove, true);

            if ($active && $absent) {
                $missing[] = $code;
            }
        }

        if ($missing !== []) {
            throw new MissingRequiredAttributes($missing);
        }

        $facts = [];

        foreach ($next as $code => $value) {
            $definition = $definitions[(string) $code] ?? null;

            if ($definition instanceof StoredAttribute) {
                array_push($facts, ...$this->facts($definition, $owner->product, $owner->variant, $value));
            }
        }

        return new PreparedAttributes($owner, $next, $facts);
    }

    /** Writes a prepared change: the owner's projection rows, then its document. The owner row must exist. */
    public function apply(PreparedAttributes $prepared): void
    {
        $db = $this->db();
        $owner = $prepared->owner;

        $projection = $db->table($this->tables->table('attribute_index'))->where('product_id', $owner->product);
        $owner->variant === null ? $projection->whereNull('variant_id') : $projection->where('variant_id', $owner->variant);
        $projection->delete();

        $this->insert($db, $prepared->facts);

        $db->table($this->tables->table($owner->table()))
            ->where('id', $owner->id())
            ->update(['attributes' => Documents::encode($prepared->document), 'updated_at' => PgTimestamp::now()]);
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

        foreach (['products', 'variants'] as $table) {
            $after = null;

            do {
                $query = $db->table($this->tables->table($table))
                    ->whereRaw('jsonb_exists(attributes, ?)', [$code])
                    ->orderBy('id')
                    ->limit(self::BATCH)
                    ->select($table === 'products' ? ['id', 'id as product_id', 'attributes'] : ['id', 'product_id', 'attributes']);

                if ($after !== null) {
                    $query->where('id', '>', $after);
                }

                $owners = $query->get()->all();
                $facts = [];

                foreach ($owners as $owner) {
                    $document = Documents::decode(Rows::string($owner, 'attributes'));

                    if (array_key_exists($code, $document)) {
                        $product = Rows::string($owner, 'product_id');
                        $variant = $table === 'variants' ? Rows::string($owner, 'id') : null;
                        array_push($facts, ...$this->facts($definition, $product, $variant, $document[$code]));
                    }

                    $after = Rows::string($owner, 'id');
                }

                $this->insert($db, $facts);
            } while (count($owners) === self::BATCH);
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
     * Attribute slots of the type on the owner's level.
     *
     * @return array<string, bool> code => required
     */
    private function slots(ConnectionInterface $db, ProductTypeId $type, AttributeOwner $owner): array
    {
        $rows = $db->table($this->tables->table('product_type_attributes').' as s')
            ->join($this->tables->table('attributes').' as a', 'a.id', '=', 's.attribute_id')
            ->where('s.product_type_id', $type->value)
            ->where('s.level', $owner->level->value)
            ->select('a.code', 's.required')
            ->get();

        $slots = [];

        foreach ($rows as $row) {
            $slots[Rows::string($row, 'code')] = Rows::bool($row, 'required');
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
