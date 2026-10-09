<?php

declare(strict_types=1);

use Commerz\Catalog\Facades\Catalog;
use Commerz\Catalog\Listings\Contracts\Listings;
use Commerz\Catalog\Listings\Data\PublishProduct;
use Commerz\Catalog\Listings\Data\UnpublishProduct;
use Commerz\Catalog\Listings\Enums\ListingStatus;
use Commerz\Catalog\Listings\Events\ProductPublished;
use Commerz\Catalog\Listings\Events\ProductUnpublished;
use Commerz\Catalog\Listings\Exceptions\ListingNotFound;
use Commerz\Catalog\Products\Data\ArchiveProduct;
use Commerz\Catalog\Products\Data\CreateProduct;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Data\ProductView;
use Commerz\Catalog\Products\Exceptions\ProductArchived;
use Commerz\Catalog\Products\Exceptions\ProductNotFound;
use Commerz\Catalog\ProductTypes\Data\CreateProductType;
use Commerz\Catalog\ProductTypes\Data\ListProductTypes;
use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Commerz\Money\Currency;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\ArchiveChannel;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\ChannelView;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Commerz\Stores\Data\ListStores;
use Commerz\Stores\Exceptions\ChannelArchived;
use Commerz\Stores\Exceptions\ChannelNotFound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function lstChannel(string $code = 'web'): ChannelView
{
    $stores = app(Stores::class);
    $store = $stores->list(new ListStores)->items[0] ?? $stores->create(new CreateStore('main', 'Main', Currency::of('RUB')));

    return app(Channels::class)->create(new CreateChannel($store->id, $code, ucfirst($code), [Currency::of('RUB')]));
}

function lstProduct(string $handle = 'basic-tee'): ProductView
{
    $label = LocalizedText::of('en', 'X');
    $type = Catalog::productTypes()->list(new ListProductTypes)->items[0]
        ?? Catalog::productTypes()->create(new CreateProductType('apparel', $label));

    return Catalog::products()->create(new CreateProduct($type->id, $handle, $label));
}

it('exposes listings as the bound singleton', function (): void {
    expect(Catalog::listings())->toBe(app(Listings::class));
});

it('publishes a product in a channel and replaces the window on a new publication', function (): void {
    $channel = lstChannel();
    $product = lstProduct();
    Event::fake([ProductPublished::class]);

    $listing = Catalog::listings()->publish(new PublishProduct($product->id, $channel->id));

    expect($listing->product->equals($product->id))->toBeTrue()
        ->and($listing->channel->equals($channel->id))->toBeTrue()
        ->and($listing->status)->toBe(ListingStatus::Published)
        ->and($listing->availableFrom)->toBeNull()
        ->and($listing->availableUntil)->toBeNull()
        ->and($listing->isAvailableAt(new DateTimeImmutable))->toBeTrue()
        ->and(Catalog::listings()->publish(new PublishProduct($product->id, $channel->id))->updatedAt)->toEqual($listing->updatedAt);

    $from = new DateTimeImmutable('2026-11-01 00:00:00', new DateTimeZone('Europe/Moscow'));
    $until = new DateTimeImmutable('2026-12-01 00:00:00+00:00');
    $windowed = Catalog::listings()->publish(new PublishProduct($product->id, $channel->id, $from, $until));

    expect($windowed->availableFrom?->getTimestamp())->toBe($from->getTimestamp())
        ->and($windowed->availableUntil?->getTimestamp())->toBe($until->getTimestamp())
        ->and($windowed->createdAt)->toEqual($listing->createdAt)
        ->and($windowed->isAvailableAt(new DateTimeImmutable('2026-10-31 20:59:59+00:00')))->toBeFalse()
        ->and($windowed->isAvailableAt(new DateTimeImmutable('2026-10-31 21:00:00+00:00')))->toBeTrue()
        ->and($windowed->isAvailableAt($until))->toBeFalse()
        ->and(Catalog::listings()->publish(new PublishProduct($product->id, $channel->id, $from, $until))->updatedAt)->toEqual($windowed->updatedAt)
        ->and(Catalog::listings()->find($product->id, $channel->id))->toEqual($windowed);

    Event::assertDispatchedTimes(ProductPublished::class, 2);
});

it('unpublishes a listing and keeps it', function (): void {
    $channel = lstChannel();
    $product = lstProduct();
    Catalog::listings()->publish(new PublishProduct($product->id, $channel->id));
    Event::fake([ProductUnpublished::class]);

    $unpublished = Catalog::listings()->unpublish(new UnpublishProduct($product->id, $channel->id));

    expect($unpublished->status)->toBe(ListingStatus::Unpublished)
        ->and($unpublished->isAvailableAt(new DateTimeImmutable))->toBeFalse()
        ->and(Catalog::listings()->unpublish(new UnpublishProduct($product->id, $channel->id))->updatedAt)->toEqual($unpublished->updatedAt)
        ->and(Catalog::listings()->publish(new PublishProduct($product->id, $channel->id))->status)->toBe(ListingStatus::Published);

    Event::assertDispatchedTimes(ProductUnpublished::class, 1);
});

it('lists the listings of a product', function (): void {
    $web = lstChannel('web');
    $pos = lstChannel('pos');
    $product = lstProduct();
    lstProduct('other');

    Catalog::listings()->publish(new PublishProduct($product->id, $web->id));
    Catalog::listings()->publish(new PublishProduct($product->id, $pos->id));
    Catalog::listings()->unpublish(new UnpublishProduct($product->id, $pos->id));

    $listings = Catalog::listings()->ofProduct($product->id);
    $ids = [$web->id->value, $pos->id->value];
    sort($ids);

    expect(array_map(fn ($listing): string => $listing->channel->value, $listings))->toBe($ids)
        ->and(Catalog::listings()->ofProduct(Catalog::products()->findByHandle('other')->id))->toBe([])
        ->and(fn () => Catalog::listings()->ofProduct(ProductId::generate()))->toThrow(ProductNotFound::class);
});

it('refuses missing or archived channels and products', function (): void {
    $channel = lstChannel();
    $archivedChannel = lstChannel('old');
    app(Channels::class)->archive(new ArchiveChannel($archivedChannel->id));
    $product = lstProduct();
    $archivedProduct = lstProduct('gone');
    Catalog::products()->archive(new ArchiveProduct($archivedProduct->id));

    expect(fn () => Catalog::listings()->publish(new PublishProduct($product->id, ChannelId::generate())))->toThrow(ChannelNotFound::class)
        ->and(fn () => Catalog::listings()->publish(new PublishProduct($product->id, $archivedChannel->id)))->toThrow(ChannelArchived::class)
        ->and(fn () => Catalog::listings()->publish(new PublishProduct($archivedProduct->id, $channel->id)))->toThrow(ProductArchived::class)
        ->and(fn () => Catalog::listings()->publish(new PublishProduct(ProductId::generate(), $channel->id)))->toThrow(ProductNotFound::class)
        ->and(fn () => Catalog::listings()->unpublish(new UnpublishProduct($product->id, $channel->id)))->toThrow(ListingNotFound::class)
        ->and(fn () => Catalog::listings()->find($product->id, $channel->id))->toThrow(ListingNotFound::class)
        ->and(Catalog::listings()->ofProduct($product->id))->toBe([]);
});

it('lets an archived product be unpublished', function (): void {
    $channel = lstChannel();
    $product = lstProduct();
    Catalog::listings()->publish(new PublishProduct($product->id, $channel->id));
    Catalog::products()->archive(new ArchiveProduct($product->id));

    expect(Catalog::listings()->unpublish(new UnpublishProduct($product->id, $channel->id))->status)->toBe(ListingStatus::Unpublished);
});

it('refuses a window that ends before it starts', function (): void {
    $at = new DateTimeImmutable('2026-11-01');

    expect(fn () => new PublishProduct(ProductId::generate(), ChannelId::generate(), $at, $at))->toThrow(InvalidCommand::class, 'availableUntil');
});
