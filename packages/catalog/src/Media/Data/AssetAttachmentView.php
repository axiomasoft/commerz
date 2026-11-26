<?php

declare(strict_types=1);

namespace Commerz\Catalog\Media\Data;

use Commerz\Catalog\Media\Enums\AssetRole;
use Commerz\Catalog\Options\Data\OptionValueId;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Measures\LocalizedText;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class AssetAttachmentView
{
    public function __construct(
        public AssetAttachmentId $id,
        public ProductId $product,
        public string $assetId,
        public AssetRole $role,
        public ?LocalizedText $alt,
        public int $position,
        public ?OptionValueId $optionValue,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
