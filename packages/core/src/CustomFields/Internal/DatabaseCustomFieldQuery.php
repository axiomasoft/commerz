<?php

declare(strict_types=1);

namespace Commerz\CustomFields\Internal;

use Closure;
use Commerz\Configuration\CommerzOptions;
use Commerz\CustomFields\Contracts\CustomFieldQuery;
use Commerz\CustomFields\Contracts\CustomFieldRegistry;
use Commerz\CustomFields\Data\CustomFieldDefinition;
use Commerz\CustomFields\Data\CustomFieldFilter;
use Commerz\CustomFields\Data\CustomFieldIndexFact;
use Commerz\CustomFields\Enums\CustomFieldFilterOperator;
use Commerz\CustomFields\Enums\FieldVisibility;
use Commerz\CustomFields\Exceptions\CustomFieldNotFilterable;
use Commerz\CustomFields\Exceptions\CustomFieldVisibilityViolation;
use Commerz\Errors\InvalidCommand;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Override;
use Packman\Attributes\Internal;

/**
 * Compiles typed, visibility-enforced SQL queries over `cmz_custom_field_index`.
 */
#[Internal]
final readonly class DatabaseCustomFieldQuery implements CustomFieldQuery
{
    private const string COLUMN_IDENTIFIER_PATTERN = '/\A[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?\z/';

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $options,
        private TableNames $tables,
        private CustomFieldRegistry $registry,
    ) {}

    #[Override]
    public function applyFilters(
        Builder $query,
        string $targetAggregate,
        string $aggregateIdColumn,
        array $filters,
        FieldVisibility $audience = FieldVisibility::PublicStorefront,
    ): Builder {
        if (preg_match(self::COLUMN_IDENTIFIER_PATTERN, $aggregateIdColumn) !== 1) {
            throw new InvalidCommand(
                'aggregateIdColumn',
                "'{$aggregateIdColumn}' is not a valid SQL column reference",
                'custom_fields.invalid_filter',
            );
        }

        if ($filters === []) {
            return $query;
        }

        $qualifiedOuterColumn = $this->qualifyOuterColumn($query, $aggregateIdColumn);
        $indexTable = $this->tables->table('custom_field_index');
        $isPostgres = $this->isPostgres($query->getConnection());

        foreach ($filters as $filter) {
            $definition = $this->resolveFilterableDefinition($targetAggregate, $filter, $audience);
            $predicate = $this->compilePredicate($definition, $filter, $isPostgres, 'i');

            $query->whereExists(static function (Builder $sub) use ($indexTable, $targetAggregate, $definition, $audience, $qualifiedOuterColumn, $predicate): void {
                $sub->selectRaw('1')
                    ->from($indexTable.' as i')
                    ->where('i.target_aggregate', $targetAggregate)
                    ->where('i.namespace', $definition->namespace)
                    ->where('i.field_key', $definition->key)
                    ->whereColumn('i.aggregate_id', $qualifiedOuterColumn);

                if ($audience === FieldVisibility::PublicStorefront) {
                    $sub->where('i.visibility', FieldVisibility::PublicStorefront->value);
                }

                $predicate($sub);
            });
        }

        return $query;
    }

    #[Override]
    public function matchingIds(
        string $targetAggregate,
        array $filters,
        FieldVisibility $audience = FieldVisibility::PublicStorefront,
    ): array {
        if ($filters === []) {
            return [];
        }

        $db = $this->db();
        $indexTable = $this->tables->table('custom_field_index');
        $isPostgres = $this->isPostgres($db);

        $firstFilter = $filters[0];
        $firstDefinition = $this->resolveFilterableDefinition($targetAggregate, $firstFilter, $audience);

        $query = $db->table($indexTable.' as base')
            ->select('base.aggregate_id')
            ->distinct()
            ->where('base.target_aggregate', $targetAggregate)
            ->where('base.namespace', $firstDefinition->namespace)
            ->where('base.field_key', $firstDefinition->key);

        if ($audience === FieldVisibility::PublicStorefront) {
            $query->where('base.visibility', FieldVisibility::PublicStorefront->value);
        }

        $this->compilePredicate($firstDefinition, $firstFilter, $isPostgres, 'base')($query);

        $remaining = array_slice($filters, 1);
        if ($remaining !== []) {
            $this->applyFilters($query, $targetAggregate, 'base.aggregate_id', $remaining, $audience);
        }

        $rows = $query->orderBy('base.aggregate_id')->get()->all();
        $ids = [];

        foreach ($rows as $row) {
            $ids[] = Rows::string($row, 'aggregate_id');
        }

        return $ids;
    }

    private function resolveFilterableDefinition(
        string $targetAggregate,
        CustomFieldFilter $filter,
        FieldVisibility $audience,
    ): CustomFieldDefinition {
        $definition = $this->registry->get($targetAggregate, $filter->namespace, $filter->key);

        if (! $definition->isVisibleTo($audience)) {
            throw new CustomFieldVisibilityViolation(
                $definition->qualifiedKey(),
                "audience '{$audience->value}' cannot filter on '{$definition->visibility->value}' custom field",
            );
        }

        if (! $definition->isFilterable) {
            throw new CustomFieldNotFilterable($targetAggregate, $definition->qualifiedKey());
        }

        if (! in_array($filter->operator, $definition->type->operators(), true)) {
            throw new InvalidCommand(
                'filters',
                "operator '{$filter->operator->value}' is not supported for '{$definition->qualifiedKey()}' ({$definition->type->value})",
                'custom_fields.invalid_filter',
            );
        }

        return $definition;
    }

    /**
     * @param  'i'|'base'  $alias
     * @return Closure(Builder): void
     */
    private function compilePredicate(
        CustomFieldDefinition $definition,
        CustomFieldFilter $filter,
        bool $isPostgres,
        string $alias,
    ): Closure {
        return match ($filter->operator) {
            CustomFieldFilterOperator::Equals => $this->compileEquals($definition, $filter->values[0], $isPostgres, $alias),
            CustomFieldFilterOperator::In => $this->compileIn($definition, $filter->values, $isPostgres, $alias),
            CustomFieldFilterOperator::Range => $this->compileRange($definition, $filter->values[0] ?? null, $filter->values[1] ?? null, $isPostgres, $alias),
        };
    }

    /**
     * @param  'i'|'base'  $alias
     * @return Closure(Builder): void
     */
    private function compileEquals(
        CustomFieldDefinition $definition,
        mixed $rawValue,
        bool $isPostgres,
        string $alias,
    ): Closure {
        $fact = CustomFieldValueCodec::normalizeFilterSingleValue($rawValue, $definition);

        return static function (Builder $q) use ($fact, $isPostgres, $alias): void {
            self::applyFactEquality($q, $fact, $isPostgres, $alias);
        };
    }

    /**
     * @param  list<mixed>  $rawValues
     * @param  'i'|'base'  $alias
     * @return Closure(Builder): void
     */
    private function compileIn(
        CustomFieldDefinition $definition,
        array $rawValues,
        bool $isPostgres,
        string $alias,
    ): Closure {
        $facts = [];
        foreach ($rawValues as $rawValue) {
            $facts[] = CustomFieldValueCodec::normalizeFilterSingleValue($rawValue, $definition);
        }

        return static function (Builder $q) use ($facts, $isPostgres, $alias): void {
            $q->where(static function (Builder $disjunction) use ($facts, $isPostgres, $alias): void {
                foreach ($facts as $fact) {
                    $disjunction->orWhere(static function (Builder $branch) use ($fact, $isPostgres, $alias): void {
                        self::applyFactEquality($branch, $fact, $isPostgres, $alias);
                    });
                }
            });
        };
    }

    /**
     * @param  'i'|'base'  $alias
     * @return Closure(Builder): void
     */
    private function compileRange(
        CustomFieldDefinition $definition,
        mixed $rawMin,
        mixed $rawMax,
        bool $isPostgres,
        string $alias,
    ): Closure {
        /** @var list<array{0: 'num'|'day', 1: '>='|'<=', 2: string}> $bounds */
        $bounds = [];

        foreach ([['>=', $rawMin], ['<=', $rawMax]] as [$sqlOperator, $boundInput]) {
            if ($boundInput === null) {
                continue;
            }

            $fact = CustomFieldValueCodec::normalizeFilterSingleValue($boundInput, $definition);

            if ($fact->number !== null) {
                $bounds[] = ['num', $sqlOperator, $fact->number];
            } elseif ($fact->day !== null) {
                $bounds[] = ['day', $sqlOperator, $fact->day];
            } else {
                throw new InvalidCommand(
                    'filters',
                    "range filter on '{$definition->qualifiedKey()}' requires numeric or date bounds",
                    'custom_fields.invalid_filter',
                );
            }
        }

        return static function (Builder $q) use ($bounds, $isPostgres, $alias): void {
            foreach ($bounds as [$column, $operator, $value]) {
                self::applyRangeBound($q, $column, $operator, $value, $isPostgres, $alias);
            }
        };
    }

    /**
     * @param  'i'|'base'  $alias
     */
    private static function applyFactEquality(Builder $q, CustomFieldIndexFact $fact, bool $isPostgres, string $alias): void
    {
        if ($fact->term !== null) {
            $q->where("{$alias}.term", $fact->term);

            return;
        }

        if ($fact->flag !== null) {
            if ($isPostgres) {
                $sql = $alias === 'i' ? 'i.flag = ?::boolean' : 'base.flag = ?::boolean';
                $q->whereRaw($sql, [$fact->flag ? 'true' : 'false']);
            } else {
                $q->where("{$alias}.flag", $fact->flag ? 1 : 0);
            }

            return;
        }

        if ($fact->day !== null) {
            if ($isPostgres) {
                $sql = $alias === 'i' ? 'i.day = ?::date' : 'base.day = ?::date';
                $q->whereRaw($sql, [$fact->day]);
            } else {
                $q->where("{$alias}.day", $fact->day);
            }

            return;
        }

        if ($fact->number !== null) {
            if ($isPostgres) {
                $sql = $alias === 'i' ? 'i.num = ?::numeric' : 'base.num = ?::numeric';
                $q->whereRaw($sql, [$fact->number]);
            } else {
                $sql = $alias === 'i' ? 'i.num = CAST(? AS NUMERIC)' : 'base.num = CAST(? AS NUMERIC)';
                $q->whereRaw($sql, [$fact->number]);
            }
        }
    }

    /**
     * @param  'num'|'day'  $column
     * @param  '>='|'<='  $operator
     * @param  'i'|'base'  $alias
     */
    private static function applyRangeBound(
        Builder $q,
        string $column,
        string $operator,
        string $value,
        bool $isPostgres,
        string $alias,
    ): void {
        if ($column === 'num') {
            $sql = match ([$alias, $operator, $isPostgres]) {
                ['i', '>=', true] => 'i.num >= ?::numeric',
                ['i', '<=', true] => 'i.num <= ?::numeric',
                ['base', '>=', true] => 'base.num >= ?::numeric',
                ['base', '<=', true] => 'base.num <= ?::numeric',
                ['i', '>=', false] => 'i.num >= CAST(? AS NUMERIC)',
                ['i', '<=', false] => 'i.num <= CAST(? AS NUMERIC)',
                ['base', '>=', false] => 'base.num >= CAST(? AS NUMERIC)',
                ['base', '<=', false] => 'base.num <= CAST(? AS NUMERIC)',
            };
            $q->whereRaw($sql, [$value]);

            return;
        }

        $sql = match ([$alias, $operator, $isPostgres]) {
            ['i', '>=', true] => 'i.day >= ?::date',
            ['i', '<=', true] => 'i.day <= ?::date',
            ['base', '>=', true] => 'base.day >= ?::date',
            ['base', '<=', true] => 'base.day <= ?::date',
            ['i', '>=', false] => 'i.day >= ?',
            ['i', '<=', false] => 'i.day <= ?',
            ['base', '>=', false] => 'base.day >= ?',
            ['base', '<=', false] => 'base.day <= ?',
        };
        $q->whereRaw($sql, [$value]);
    }

    private function isPostgres(ConnectionInterface $connection): bool
    {
        return $connection instanceof Connection && $connection->getDriverName() === 'pgsql';
    }

    private function qualifyOuterColumn(Builder $query, string $column): string
    {
        if (str_contains($column, '.')) {
            return $column;
        }

        $from = $query->from;
        if (! is_string($from) || trim($from) === '') {
            return $column;
        }

        if (preg_match('/\s+as\s+([a-zA-Z_][a-zA-Z0-9_]*)\z/i', trim($from), $matches) === 1) {
            return "{$matches[1]}.{$column}";
        }

        $trimmed = trim($from);
        if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $trimmed) === 1) {
            return "{$trimmed}.{$column}";
        }

        return $column;
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->options->connection);
    }
}
