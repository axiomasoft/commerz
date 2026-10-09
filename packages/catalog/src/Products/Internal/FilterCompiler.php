<?php

declare(strict_types=1);

namespace Commerz\Catalog\Products\Internal;

use Closure;
use Commerz\Catalog\Attributes\Contracts\AttributeType;
use Commerz\Catalog\Attributes\Data\IndexFact;
use Commerz\Catalog\Attributes\Enums\FilterOperator;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotFilterable;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotFound;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Commerz\Catalog\Attributes\Internal\AttributeWriter;
use Commerz\Catalog\Attributes\Internal\StoredAttribute;
use Commerz\Catalog\Common\Internal\Rows;
use Commerz\Catalog\Products\Data\AttributeFilter;
use Commerz\Catalog\Products\Data\VariantFilter;
use Commerz\Configuration\CommerzConfig;
use Commerz\Errors\InvalidCommand;
use Commerz\Plugins\Contracts\Contributions;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;

/**
 * Compiles AttributeFilter/VariantFilter into conditions on one variant row `v` of the product row `p`
 * (knowledge/catalog-design#filters, CAT-03). Filter values are normalized and indexed by the attribute's own type,
 * so they meet the projection in the form the writer stored. Values reach SQL only as bindings.
 */
final readonly class FilterCompiler
{
    /** Projection column of a fact => the SQL type its binding is cast to. */
    private const array CASTS = ['choice_id' => 'uuid', 'num' => 'numeric', 'flag' => 'boolean', 'day' => 'date', 'term' => 'varchar'];

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzConfig $config,
        private TableNames $tables,
        private Contributions $contributions,
        private AttributeWriter $attributes,
    ) {}

    /**
     * @param  list<AttributeFilter|VariantFilter>  $filters
     * @return list<Closure(Builder): void>|null conditions on the variant query; null when no variant can match
     *
     * @throws AttributeNotFound|AttributeNotFilterable|InvalidAttributeValue|InvalidCommand
     */
    public function compile(array $filters): ?array
    {
        $attributeFilters = array_values(array_filter($filters, static fn (object $f): bool => $f instanceof AttributeFilter));
        $variantFilters = array_values(array_filter($filters, static fn (object $f): bool => $f instanceof VariantFilter));
        $definitions = $this->attributes->read(array_map(static fn (AttributeFilter $f): string => $f->attribute, $attributeFilters));
        $conditions = [];

        foreach ($attributeFilters as $filter) {
            array_push($conditions, ...$this->attribute($filter, $definitions[$filter->attribute] ?? throw new AttributeNotFound($filter->attribute)));
        }

        $values = $this->optionValues($variantFilters);

        if ($values === null) {
            return null;
        }

        $variantValues = $this->tables->table('variant_option_values');

        foreach ($values as $value) {
            $conditions[] = static function (Builder $v) use ($variantValues, $value): void {
                $v->whereExists(static fn (Builder $x) => $x->selectRaw('1')
                    ->from($variantValues.' as x')
                    ->whereColumn('x.variant_id', 'v.id')
                    ->where('x.option_value_id', $value));
            };
        }

        return $conditions;
    }

    /** @return list<Closure(Builder): void> */
    private function attribute(AttributeFilter $filter, StoredAttribute $definition): array
    {
        if (! $definition->projected()) {
            throw new AttributeNotFilterable($filter->attribute);
        }

        $type = $this->contributions->get(AttributeType::KIND, $definition->type, AttributeType::class);

        if (! in_array($filter->operator, $type->operators(), true)) {
            throw new InvalidCommand('filters', "'{$filter->attribute}' does not support the {$filter->operator->value} filter", 'catalog.unsupported_filter');
        }

        if ($definition->type === 'text') {
            return [$this->text($filter, $type, $definition)];
        }

        return match ($filter->operator) {
            // Every fact of the value must be present: for a multi-choice value equals means "contains".
            FilterOperator::Equals => array_map(
                fn (IndexFact $fact): Closure => $this->exists($definition, static function (Builder $i) use ($fact): void {
                    [$column, $value] = self::column($fact);
                    $i->whereRaw("i.{$column} = ?::".self::CASTS[$column], [$value]);
                }),
                $this->facts($type, $definition, $filter->values[0]),
            ),
            FilterOperator::In => [$this->exists($definition, $this->any($type, $definition, $filter->values))],
            FilterOperator::Range => [$this->exists($definition, $this->range($type, $definition, $filter->values[0], $filter->values[1] ?? null))],
        };
    }

    /** The indexed term is a 255-character prefix; equality also compares the complete canonical document value. */
    private function text(AttributeFilter $filter, AttributeType $type, StoredAttribute $definition): Closure
    {
        $values = [];

        foreach ($filter->values as $input) {
            $value = $type->normalize($input, $definition->definition);

            if (! is_string($value)) {
                throw new InvalidAttributeValue($filter->attribute, 'expected a text value');
            }

            $values[] = $value;
        }

        return $this->exists($definition, static function (Builder $i) use ($values, $filter): void {
            $i->where(static function (Builder $any) use ($values, $filter): void {
                foreach ($values as $value) {
                    $any->orWhereRaw(
                        '(i.term = ?::varchar and (case when i.variant_id is null then p.attributes else v.attributes end) ->> ? = ?::text)',
                        [mb_substr($value, 0, 255), $filter->attribute, $value],
                    );
                }
            });
        });
    }

    /**
     * @param  list<mixed>  $values
     * @return Closure(Builder): void
     */
    private function any(AttributeType $type, StoredAttribute $definition, array $values): Closure
    {
        $byColumn = [];

        foreach ($values as $input) {
            foreach ($this->facts($type, $definition, $input) as $fact) {
                [$column, $value] = self::column($fact);
                $byColumn[$column][$value] = $value;
            }
        }

        return static function (Builder $i) use ($byColumn): void {
            $i->where(static function (Builder $any) use ($byColumn): void {
                foreach ($byColumn as $column => $columnValues) {
                    $placeholders = implode(', ', array_fill(0, count($columnValues), '?::'.self::CASTS[$column]));
                    $any->orWhereRaw("i.{$column} in ({$placeholders})", array_values($columnValues));
                }
            });
        };
    }

    /** @return Closure(Builder): void */
    private function range(AttributeType $type, StoredAttribute $definition, mixed $min, mixed $max): Closure
    {
        $bounds = [];

        foreach (['>=' => $min, '<=' => $max] as $operator => $input) {
            if ($input === null) {
                continue;
            }

            $facts = $this->facts($type, $definition, $input);
            [$column, $value] = count($facts) === 1 ? self::column($facts[0]) : [null, ''];

            if ($column !== 'num' && $column !== 'day') {
                throw new InvalidCommand('filters', "a bound of '{$definition->definition->code}' must be a single number or date", 'catalog.invalid_filter');
            }

            $bounds[] = [$column, $operator, $value];
        }

        return static function (Builder $i) use ($bounds): void {
            foreach ($bounds as [$column, $operator, $value]) {
                $i->whereRaw("i.{$column} {$operator} ?::".self::CASTS[$column], [$value]);
            }
        };
    }

    /**
     * The condition "the variant has a fact of the attribute matching `$match`"; a product-level fact
     * (`variant_id is null`) belongs to every variant of its product.
     *
     * @param  Closure(Builder): void  $match
     * @return Closure(Builder): void
     */
    private function exists(StoredAttribute $definition, Closure $match): Closure
    {
        $index = $this->tables->table('attribute_index');

        return static function (Builder $v) use ($index, $definition, $match): void {
            $v->whereExists(static function (Builder $i) use ($index, $definition, $match): void {
                $i->selectRaw('1')
                    ->from($index.' as i')
                    ->where('i.attribute_id', $definition->id)
                    ->whereColumn('i.product_id', 'p.id')
                    ->where(static fn (Builder $owner) => $owner->whereNull('i.variant_id')->orWhereColumn('i.variant_id', 'v.id'));
                $match($i);
            });
        };
    }

    /**
     * Facts of one filter value, normalized by the attribute's type exactly like a written value. A multi-valued type
     * (multi_enum) refuses a single code; the code is then taken as a one-element list.
     *
     * @return non-empty-list<IndexFact>
     */
    private function facts(AttributeType $type, StoredAttribute $definition, mixed $input): array
    {
        try {
            $value = $type->normalize($input, $definition->definition);
        } catch (InvalidAttributeValue $e) {
            if (is_array($input)) {
                throw $e;
            }

            try {
                $value = $type->normalize([$input], $definition->definition);
            } catch (InvalidAttributeValue) {
                throw $e;
            }
        }

        $facts = $type->index($value, $definition->definition);

        if ($facts === []) {
            throw new InvalidCommand('filters', "the value of '{$definition->definition->code}' gives nothing to filter by", 'catalog.invalid_filter');
        }

        return $facts;
    }

    /** @return array{0: key-of<self::CASTS>, 1: string} the projection column of a fact and its value as a binding */
    private static function column(IndexFact $fact): array
    {
        return match (true) {
            $fact->choice !== null => ['choice_id', $fact->choice->value],
            $fact->number !== null => ['num', $fact->number],
            $fact->flag !== null => ['flag', $fact->flag ? 'true' : 'false'],
            $fact->day !== null => ['day', $fact->day],
            default => ['term', (string) $fact->term],
        };
    }

    /**
     * Value ids of every axis pair of the variant filters; null when a pair names an unknown option or value
     * (a stale storefront facet matches nothing).
     *
     * @param  list<VariantFilter>  $filters
     * @return list<string>|null
     */
    private function optionValues(array $filters): ?array
    {
        $pairs = [];

        foreach ($filters as $filter) {
            foreach ($filter->axes as $option => $value) {
                $pairs["{$option}\0{$value}"] = [$option, $value];
            }
        }

        if ($pairs === []) {
            return [];
        }

        $rows = $this->connections->connection($this->config->connection)
            ->table($this->tables->table('options').' as o')
            ->join($this->tables->table('option_values').' as ov', 'ov.option_id', '=', 'o.id')
            ->where(static function (Builder $any) use ($pairs): void {
                foreach ($pairs as [$option, $value]) {
                    $any->orWhere(static fn (Builder $pair) => $pair->where('o.code', $option)->where('ov.code', $value));
                }
            })
            ->select('o.code as option', 'ov.code as value', 'ov.id')
            ->get();

        $ids = [];

        foreach ($rows as $row) {
            $ids[Rows::string($row, 'option')."\0".Rows::string($row, 'value')] = Rows::string($row, 'id');
        }

        return count($ids) === count($pairs) ? array_values($ids) : null;
    }
}
