<?php

declare(strict_types=1);

use Commerz\Catalog\Media\Data\AssetAttachmentId;
use Commerz\Catalog\Media\Data\AttachAsset;
use Commerz\Catalog\Media\Data\UpdateAssetAttachment;
use Commerz\Catalog\Media\Enums\AssetRole;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;

it('validates Media commands and exposes all 5 asset roles', function (): void {
    $roles = array_map(static fn (AssetRole $r): string => $r->value, AssetRole::cases());
    expect($roles)->toBe(['thumbnail', 'gallery', 'swatch', 'video', 'document']);

    $product = ProductId::generate();
    $attach = new AttachAsset($product, 'asset-123', AssetRole::Gallery, LocalizedText::of('en', 'Front view'), 2);
    expect($attach->assetId)->toBe('asset-123')->and($attach->position)->toBe(2);

    expect(fn () => new AttachAsset($product, '   ', AssetRole::Thumbnail))->toThrow(InvalidCommand::class)
        ->and(fn () => new AttachAsset($product, str_repeat('a', 192), AssetRole::Thumbnail))->toThrow(InvalidCommand::class)
        ->and(fn () => new AttachAsset($product, 'asset-1', AssetRole::Thumbnail, position: -1))->toThrow(InvalidCommand::class)
        ->and(fn () => new UpdateAssetAttachment(AssetAttachmentId::generate(), position: -1))->toThrow(InvalidCommand::class);
});
