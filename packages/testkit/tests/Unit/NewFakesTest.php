<?php

declare(strict_types=1);

use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Data\ProductView;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\ProductTypes\Data\ProductTypeId;
use Commerz\Catalog\Search\Data\CatalogSearchQuery;
use Commerz\Context\CommerceContext;
use Commerz\Measures\LocalizedText;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Assets\Data\AssetRef;
use Commerz\Ports\Assets\Data\ResolvedAsset;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Ports\Delivery\Data\PickupPoint;
use Commerz\Ports\Delivery\Data\PickupPointLimits;
use Commerz\Ports\Delivery\Data\PickupPointQuery;
use Commerz\Ports\Delivery\Enums\DeliveryMode;
use Commerz\Ports\StoredValue\Data\CaptureStoredValue;
use Commerz\Ports\StoredValue\Data\HoldStoredValue;
use Commerz\Ports\StoredValue\Data\RefundToStoredValue;
use Commerz\Ports\StoredValue\Data\ReleaseStoredValue;
use Commerz\Ports\StoredValue\Exceptions\InsufficientStoredValue;
use Commerz\Ports\StoredValue\Exceptions\StoredValueUnavailable;
use Commerz\Stores\Data\ChannelId;
use Commerz\Testkit\Fakes\FakeAssetResolver;
use Commerz\Testkit\Fakes\FakePickupPoints;
use Commerz\Testkit\Fakes\FakeSearchEngine;
use Commerz\Testkit\Fakes\FakeStoredValue;
use Commerz\Testkit\Fakes\FrozenClock;

it('FakeStoredValue enforces idempotent hold, capture, release, refundCredit, and freeze', function (): void {
    $usd = Currency::of('USD');
    $sv = (new FakeStoredValue)->seed('gc-1', Money::ofMinor(1000, $usd));

    $holdCmd = new HoldStoredValue('gc-1', 'order:1', Money::ofMinor(600, $usd), 'hold-key-1');
    $hold1 = $sv->hold($holdCmd);
    $hold2 = $sv->hold($holdCmd);

    expect($hold1->holdId)->toBe($hold2->holdId)
        ->and($sv->heldOf('gc-1')->amountMinor)->toBe(600);

    expect(fn () => $sv->hold(new HoldStoredValue('gc-1', 'order:2', Money::ofMinor(500, $usd), 'hold-key-2')))
        ->toThrow(InsufficientStoredValue::class);

    $capCmd = new CaptureStoredValue($hold1->holdId, 'pay:1', 'cap-key-1', Money::ofMinor(400, $usd));
    $cap1 = $sv->capture($capCmd);
    $cap2 = $sv->capture($capCmd);

    expect($cap1->journalId)->toBe($cap2->journalId)
        ->and($sv->balanceOf('gc-1')->amountMinor)->toBe(600)
        ->and($sv->heldOf('gc-1')->amountMinor)->toBe(200);

    $relCmd = new ReleaseStoredValue($hold1->holdId, 'rel-key-1');
    $sv->release($relCmd);
    $sv->release($relCmd);

    expect($sv->heldOf('gc-1')->amountMinor)->toBe(0);

    $refCmd = new RefundToStoredValue(Money::ofMinor(250, $usd), 'refund:1', 'ref-key-1', accountId: 'gc-1');
    $credit1 = $sv->refundCredit($refCmd);
    $credit2 = $sv->refundCredit($refCmd);

    expect($credit1->journalId)->toBe($credit2->journalId)
        ->and($sv->balanceOf('gc-1')->amountMinor)->toBe(850);

    $sv->freeze('gc-1', 'fraud_review');
    expect(fn () => $sv->hold(new HoldStoredValue('gc-1', 'order:3', Money::ofMinor(100, $usd), 'hold-key-3')))
        ->toThrow(StoredValueUnavailable::class);
});

it('FakeSearchEngine indexes, paginates with cursor, and removes products', function (): void {
    $clock = new FrozenClock('2026-01-01T00:00:00+00:00');
    $now = $clock->now();
    $type = ProductTypeId::generate();
    $p1 = new ProductView(ProductId::generate(), $type, 'alpha-mug', LocalizedText::of('en', 'Alpha Mug'), null, ProductStatus::Active, [], [], $now, $now);
    $p2 = new ProductView(ProductId::generate(), $type, 'beta-shirt', LocalizedText::of('en', 'Beta Shirt'), null, ProductStatus::Active, [], [], $now, $now);

    $engine = (new FakeSearchEngine)->add($p1, $p2);

    $page1 = $engine->search(new CatalogSearchQuery(first: 1));
    expect($page1->total)->toBe(2)
        ->and($page1->items())->toHaveCount(1)
        ->and($page1->items()[0]->handle)->toBe('alpha-mug')
        ->and($page1->hasMore())->toBeTrue();

    $page2 = $engine->search(new CatalogSearchQuery(first: 1, after: $page1->nextCursor()));
    expect($page2->items())->toHaveCount(1)
        ->and($page2->items()[0]->handle)->toBe('beta-shirt')
        ->and($page2->hasMore())->toBeFalse();

    $engine->removeProducts([$p1->id]);
    expect($engine->search(new CatalogSearchQuery)->total)->toBe(1);
});

it('FakePickupPoints filters by country, city, locker flag, and parcel limits', function (): void {
    $usd = Currency::of('USD');
    $clock = new FrozenClock('2026-01-01T00:00:00+00:00');
    $context = CommerceContext::for(ChannelId::generate(), $usd, 'en', $clock->now());

    $smallLocker = new PickupPoint(
        id: 'locker-1',
        provider: 'fake',
        name: 'Small Locker',
        mode: DeliveryMode::PickupPoint,
        address: new Address('US', 'US-CA', 'San Francisco', '94107'),
        limits: new PickupPointLimits(maxWeightGrams: 1000),
        isLocker: true,
    );
    $counter = new PickupPoint(
        id: 'counter-1',
        provider: 'fake',
        name: 'Main Counter',
        mode: DeliveryMode::LocalPickup,
        address: new Address('US', 'US-CA', 'San Francisco', '94107'),
        isLocker: false,
    );

    $fakes = new FakePickupPoints('fake')->add($smallLocker, $counter);

    $heavy = $fakes->search(new PickupPointQuery(
        context: $context,
        country: 'US',
        city: 'San Francisco',
        parcel: new Parcel(2000, Money::ofMinor(1000, $usd)),
        onlyLockers: true,
    ));
    expect($heavy)->toBe([]);

    $light = $fakes->search(new PickupPointQuery(
        context: $context,
        country: 'US',
        city: 'San Francisco',
        parcel: new Parcel(500, Money::ofMinor(1000, $usd)),
        onlyLockers: true,
    ));
    expect($light)->toHaveCount(1)
        ->and($light[0]->id)->toBe('locker-1')
        ->and($fakes->find('counter-1', $context)?->id)->toBe('counter-1');
});

it('FakeAssetResolver resolves assets, variants, batches, and signed URLs', function (): void {
    $clock = new FrozenClock('2026-01-01T00:00:00+00:00');
    $ref1 = AssetRef::of('media', 'img-1');
    $ref2 = AssetRef::of('media', 'missing');
    $resolved = new ResolvedAsset(
        ref: $ref1,
        url: 'https://cdn.example.com/img-1.jpg',
        mimeType: 'image/jpeg',
        variants: ['thumb' => 'https://cdn.example.com/img-1-thumb.jpg'],
    );

    $resolver = (new FakeAssetResolver)->add($resolved);

    expect($resolver->resolve($ref1)?->url)->toBe('https://cdn.example.com/img-1.jpg')
        ->and($resolver->resolve($ref1, 'thumb')?->url)->toBe('https://cdn.example.com/img-1-thumb.jpg')
        ->and($resolver->resolve($ref2))->toBeNull()
        ->and($resolver->resolveMany([$ref1, $ref2]))->toHaveKey('media:img-1')
        ->and($resolver->resolveMany([$ref1, $ref2]))->not->toHaveKey('media:missing');

    $expires = $clock->now()->modify('+1 hour');
    $signed = $resolver->signedUrl($ref1, $expires, 'thumb');

    expect($signed)->toBe('https://cdn.example.com/img-1-thumb.jpg?expires='.$expires->getTimestamp())
        ->and($resolver->signedUrl($ref2, $expires))->toBeNull();
});
