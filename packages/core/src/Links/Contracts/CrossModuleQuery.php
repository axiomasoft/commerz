<?php

declare(strict_types=1);

namespace Commerz\Links\Contracts;

use Closure;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Api;

/**
 * N+1-free cross-module query helper over `cmz_entity_links` without foreign-key coupling
 * between domain packages.
 */
#[Api(since: '0.1')]
interface CrossModuleQuery
{
    /**
     * Loads linked right-side entities for many left entity IDs in at most 2 queries:
     * 1 batch query to `cmz_entity_links` and 1 batch call to `$loader` for all distinct right IDs.
     *
     * @template TTarget
     *
     * @param  list<string>  $leftIds
     * @param  Closure(list<string>): array<string, TTarget>  $loader
     * @return array<string, list<TTarget>>
     */
    public function getLinkedForMany(string $linkCode, array $leftIds, Closure $loader): array;

    /**
     * Loads linked left-side entities for many right entity IDs in at most 2 queries:
     * 1 batch query to `cmz_entity_links` and 1 batch call to `$loader` for all distinct left IDs.
     *
     * @template TSource
     *
     * @param  list<string>  $rightIds
     * @param  Closure(list<string>): array<string, TSource>  $loader
     * @return array<string, list<TSource>>
     */
    public function getInverseLinkedForMany(string $linkCode, array $rightIds, Closure $loader): array;

    /**
     * Filters a query builder using an `EXISTS` subquery against `cmz_entity_links`.
     * Set `$inverse = true` when filtering right-side entities linked from a left-side `$targetId`.
     *
     * @param  literal-string  $idColumn
     */
    public function whereLinked(
        Builder $query,
        string $linkCode,
        string $idColumn,
        ?string $targetId = null,
        bool $inverse = false,
    ): Builder;
}
