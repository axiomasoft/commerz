<?php

declare(strict_types=1);

namespace Commerz\CustomFields\Contracts;

use Commerz\CustomFields\Data\CustomFieldFilter;
use Commerz\CustomFields\Enums\FieldVisibility;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Api;

/**
 * Compiles visibility-safe SQL filter predicates against `cmz_custom_field_index`.
 */
#[Api(since: '0.1')]
interface CustomFieldQuery
{
    /**
     * Appends `EXISTS` subqueries onto `$query` matching every predicate in `$filters` against `$aggregateIdColumn`.
     * Fails closed if a `PublicStorefront` audience attempts to filter on a `PrivateAdmin` field.
     *
     * @param  list<CustomFieldFilter>  $filters
     */
    public function applyFilters(
        Builder $query,
        string $targetAggregate,
        string $aggregateIdColumn,
        array $filters,
        FieldVisibility $audience = FieldVisibility::PublicStorefront,
    ): Builder;

    /**
     * Returns distinct `aggregate_id` values in `$targetAggregate` matching all `$filters` for `$audience`, sorted ascending.
     *
     * @param  list<CustomFieldFilter>  $filters
     * @return list<string>
     */
    public function matchingIds(
        string $targetAggregate,
        array $filters,
        FieldVisibility $audience = FieldVisibility::PublicStorefront,
    ): array;
}
