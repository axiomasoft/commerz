<?php

declare(strict_types=1);

namespace Commerz\Catalog\Routes\Data;

use Packman\Attributes\Api;

/** Returned when a resolved slug is a retired non-canonical route that should 301-redirect to the canonical slug. */
#[Api(since: '0.1')]
final readonly class RedirectRoute
{
    public function __construct(
        public UrlRouteView $matchedRoute,
        public UrlRouteView $canonicalRoute,
        public string $targetSlug,
        public int $statusCode = 301,
    ) {}
}
