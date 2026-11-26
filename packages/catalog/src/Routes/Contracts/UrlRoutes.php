<?php

declare(strict_types=1);

namespace Commerz\Catalog\Routes\Contracts;

use Commerz\Catalog\Routes\Data\AssignCanonicalSlug;
use Commerz\Catalog\Routes\Data\CanonicalRoute;
use Commerz\Catalog\Routes\Data\RedirectRoute;
use Commerz\Catalog\Routes\Data\ResolveRoute;
use Commerz\Catalog\Routes\Data\UrlRouteView;
use Commerz\Catalog\Routes\Exceptions\DuplicateSlug;
use Commerz\Catalog\Routes\Exceptions\RouteNotFound;
use Commerz\Stores\Data\ChannelId;
use Packman\Attributes\Api;

/** SEO URL router: canonical slugs per channel/locale/entity with 301 redirect resolution for retired slugs (INV-CAT-002). */
#[Api(since: '0.1')]
interface UrlRoutes
{
    /** @throws DuplicateSlug */
    public function assignCanonical(AssignCanonicalSlug $command): UrlRouteView;

    /** @throws RouteNotFound */
    public function resolve(ResolveRoute $query): CanonicalRoute|RedirectRoute;

    /** @throws RouteNotFound */
    public function findCanonical(ChannelId $channel, string $locale, string $entityType, string $entityId): UrlRouteView;

    /** @return list<UrlRouteView> */
    public function forEntity(string $entityType, string $entityId, ?ChannelId $channel = null, ?string $locale = null): array;
}
