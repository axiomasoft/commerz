<?php

declare(strict_types=1);

namespace Commerz\Catalog\Routes\Data;

use Packman\Attributes\Api;

/** Returned when a resolved slug is the entity's current canonical route. */
#[Api(since: '0.1')]
final readonly class CanonicalRoute
{
    public function __construct(
        public UrlRouteView $route,
    ) {}
}
