<?php

declare(strict_types=1);

namespace Commerz\Catalog\Brands\Data;

use Commerz\Catalog\Brands\Enums\BrandStatus;
use Commerz\Measures\LocalizedText;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class BrandView
{
    public function __construct(
        public BrandId $id,
        public string $code,
        public LocalizedText $name,
        public ?LocalizedText $description,
        public ?string $logoAssetId,
        public ?string $websiteUrl,
        public BrandStatus $status,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
