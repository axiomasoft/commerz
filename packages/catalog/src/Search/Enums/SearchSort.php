<?php

declare(strict_types=1);

namespace Commerz\Catalog\Search\Enums;

use Packman\Attributes\Api;

/** Deterministic sort orders supported by `CatalogSearchPort`; every order uses `id` as final tie-breaker. */
#[Api(since: '0.1')]
enum SearchSort: string
{
    case HandleAsc = 'handle_asc';
    case HandleDesc = 'handle_desc';
    case Newest = 'newest';
    case Oldest = 'oldest';
    case Relevance = 'relevance';
}
