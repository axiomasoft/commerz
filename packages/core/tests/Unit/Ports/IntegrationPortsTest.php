<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\SellableRef;
use Commerz\Ports\Assets\Data\AssetRef;
use Commerz\Ports\Assets\Data\ResolvedAsset;
use Commerz\Ports\Assets\Internal\NullAssetResolver;
use Commerz\Ports\Digital\Data\DigitalDeliveryGrant;
use Commerz\Ports\Digital\Data\GrantDigitalDelivery;
use Commerz\Ports\Digital\Data\RevokeDigitalDelivery;
use Commerz\Ports\Digital\Internal\NullDigitalDelivery;
use Commerz\Ports\Privacy\Internal\NullPiiCipher;

it('validates asset refs, resolved assets, and no-op asset resolver behavior', function (): void {
    $ref = AssetRef::of('media', 'hero-1');
    $asset = new ResolvedAsset(
        ref: $ref,
        url: 'https://cdn.example.test/hero-1.webp',
        mimeType: 'image/webp',
        sizeBytes: 24576,
        widthPx: 1200,
        heightPx: 800,
        altText: 'Product hero',
        variants: ['thumb' => 'https://cdn.example.test/hero-1-thumb.webp'],
    );

    expect((string) $ref)->toBe('media:hero-1')
        ->and($asset->variants['thumb'])->toBe('https://cdn.example.test/hero-1-thumb.webp');

    $nullResolver = new NullAssetResolver;
    expect($nullResolver->resolve($ref))->toBeNull()
        ->and($nullResolver->resolveMany([$ref]))->toBe([])
        ->and($nullResolver->signedUrl($ref, new DateTimeImmutable('2026-12-01T00:00:00Z')))->toBeNull();

    expect(fn () => new ResolvedAsset($ref, ''))->toThrow(InvalidCommand::class, 'url')
        ->and(fn () => new ResolvedAsset($ref, 'https://cdn.example.test/a.png', sizeBytes: -1))->toThrow(InvalidCommand::class, 'sizeBytes')
        ->and(fn () => new ResolvedAsset($ref, 'https://cdn.example.test/a.png', widthPx: 0))->toThrow(InvalidCommand::class, 'widthPx')
        ->and(fn () => new ResolvedAsset($ref, 'https://cdn.example.test/a.png', variants: ['Bad Key' => 'https://cdn.example.test/b.png']))
        ->toThrow(InvalidCommand::class, 'variants');
});

it('validates digital delivery commands, grants, and no-op digital delivery behavior', function (): void {
    $sellable = SellableRef::of('ebook', 'clean-arch');
    $grantCmd = new GrantDigitalDelivery(
        reference: 'fulfillment:01900000-0000-7000-8000-000000000001',
        sellable: $sellable,
        orderRef: 'order:01900000-0000-7000-8000-000000000002',
        quantity: 2,
        customer: CustomerRef::of('user', '42'),
        recipientEmail: 'buyer@example.test',
        expiresAt: new DateTimeImmutable('2026-12-01 12:00:00+03:00'),
        maxDownloads: 5,
        metadata: ['tier' => 'pro', 'seats' => 2],
    );

    expect($grantCmd->expiresAt?->format('c'))->toBe('2026-12-01T09:00:00+00:00')
        ->and($grantCmd->metadata)->toBe(['tier' => 'pro', 'seats' => 2]);

    $grant = new DigitalDeliveryGrant(
        grantId: 'grant_123',
        reference: $grantCmd->reference,
        sellable: $sellable,
        downloadUrl: 'https://downloads.example.test/grant_123',
        licenseKey: 'LIC-ABCD-1234',
        remainingDownloads: 5,
    );

    expect($grant->grantId)->toBe('grant_123')
        ->and($grant->revoked)->toBeFalse();

    $revoke = new RevokeDigitalDelivery('grant_123', 'order refunded');
    expect($revoke->reason)->toBe('order refunded');

    $nullDigital = new NullDigitalDelivery;
    $nullDigital->revoke($revoke);
    expect($nullDigital->grant($grantCmd))->toBeNull()
        ->and($nullDigital->find('grant_123'))->toBeNull();

    expect(fn () => new GrantDigitalDelivery('', $sellable, 'order:1'))->toThrow(InvalidCommand::class, 'reference')
        ->and(fn () => new GrantDigitalDelivery('ful:1', $sellable, 'order:1', quantity: 0))->toThrow(InvalidCommand::class, 'quantity')
        ->and(fn () => new DigitalDeliveryGrant('g1', 'ful:1', $sellable, remainingDownloads: -1))->toThrow(InvalidCommand::class, 'remainingDownloads')
        ->and(fn () => new RevokeDigitalDelivery(''))->toThrow(InvalidCommand::class, 'grantId');
});

it('provides passthrough no-op PII encryption and domain-separated blind indexes', function (): void {
    $cipher = new NullPiiCipher;

    expect($cipher->encrypt('+15551234567', 'customer.phone'))->toBe('+15551234567')
        ->and($cipher->decrypt('+15551234567', 'customer.phone'))->toBe('+15551234567')
        ->and($cipher->isEncrypted('+15551234567'))->toBeFalse();

    $emailIdx1 = $cipher->blindIndex('  Alice@Example.TEST ', 'customer.email');
    $emailIdx2 = $cipher->blindIndex('alice@example.test', 'customer.email');
    $otherPurposeIdx = $cipher->blindIndex('alice@example.test', 'newsletter.email');

    expect($emailIdx1)->toHaveLength(64)
        ->and($emailIdx1)->toBe($emailIdx2)
        ->and($emailIdx1)->not->toBe($otherPurposeIdx);

    expect(fn () => $cipher->encrypt('secret', 'Invalid Purpose!'))->toThrow(InvalidCommand::class, 'purpose');
});
