<?php

declare(strict_types=1);

use Commerz\Catalog\Facades\Catalog;
use Commerz\Catalog\Routes\Contracts\UrlRoutes;
use Commerz\Catalog\Routes\Data\AssignCanonicalSlug;
use Commerz\Catalog\Routes\Data\CanonicalRoute;
use Commerz\Catalog\Routes\Data\RedirectRoute;
use Commerz\Catalog\Routes\Data\ResolveRoute;
use Commerz\Catalog\Routes\Data\SeoMeta;
use Commerz\Catalog\Routes\Exceptions\DuplicateSlug;
use Commerz\Catalog\Routes\Exceptions\RouteNotFound;
use Commerz\Identity\Uuid7;
use Commerz\Stores\Data\ChannelId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Assert;

uses(RefreshDatabase::class);

it('exposes url routes as the bound singleton', function (): void {
    expect(Catalog::routes())->toBe(app(UrlRoutes::class));
});

it('assigns canonical slugs, retires old slugs for 301 RedirectRoute (INV-CAT-002), and rejects duplicate slugs', function (): void {
    $channel = ChannelId::generate();
    $productId = Uuid7::generate();
    $otherProductId = Uuid7::generate();

    $first = Catalog::routes()->assignCanonical(new AssignCanonicalSlug(
        channel: $channel,
        locale: 'en',
        slug: 'blue-t-shirt',
        entityType: 'product',
        entityId: $productId,
        seoMeta: new SeoMeta(title: 'Blue T-Shirt', description: 'Cotton tee'),
    ));

    expect($first->isCanonical)->toBeTrue()
        ->and($first->seoMeta->title)->toBe('Blue T-Shirt');

    $resolved1 = Catalog::routes()->resolve(new ResolveRoute($channel, 'en', 'blue-t-shirt'));
    Assert::assertInstanceOf(CanonicalRoute::class, $resolved1);
    expect($resolved1->route->slug)->toBe('blue-t-shirt');

    // Changing canonical slug atomically sets old slug is_canonical=false
    $second = Catalog::routes()->assignCanonical(new AssignCanonicalSlug(
        channel: $channel,
        locale: 'en',
        slug: 'organic-blue-t-shirt',
        entityType: 'product',
        entityId: $productId,
        seoMeta: new SeoMeta(title: 'Organic Blue T-Shirt'),
    ));

    expect($second->isCanonical)->toBeTrue();

    $resolvedOld = Catalog::routes()->resolve(new ResolveRoute($channel, 'en', 'blue-t-shirt'));
    Assert::assertInstanceOf(RedirectRoute::class, $resolvedOld);
    expect($resolvedOld->statusCode)->toBe(301)
        ->and($resolvedOld->targetSlug)->toBe('organic-blue-t-shirt')
        ->and($resolvedOld->matchedRoute->isCanonical)->toBeFalse()
        ->and($resolvedOld->canonicalRoute->id->equals($second->id))->toBeTrue();

    // Another entity cannot claim either the canonical or retired slug in the same (channel, locale)
    expect(fn () => Catalog::routes()->assignCanonical(new AssignCanonicalSlug(
        channel: $channel,
        locale: 'en',
        slug: 'blue-t-shirt',
        entityType: 'product',
        entityId: $otherProductId,
    )))->toThrow(DuplicateSlug::class);

    // Reverting back to the old slug flips canonical back atomically
    $reverted = Catalog::routes()->assignCanonical(new AssignCanonicalSlug(
        channel: $channel,
        locale: 'en',
        slug: 'blue-t-shirt',
        entityType: 'product',
        entityId: $productId,
    ));

    expect($reverted->isCanonical)->toBeTrue();

    $resolvedSecondAfterRevert = Catalog::routes()->resolve(new ResolveRoute($channel, 'en', 'organic-blue-t-shirt'));
    Assert::assertInstanceOf(RedirectRoute::class, $resolvedSecondAfterRevert);
    expect($resolvedSecondAfterRevert->targetSlug)->toBe('blue-t-shirt');

    $routes = Catalog::routes()->forEntity('product', $productId);
    expect($routes)->toHaveCount(2);

    expect(fn () => Catalog::routes()->resolve(new ResolveRoute($channel, 'en', 'non-existent')))
        ->toThrow(RouteNotFound::class);
});
