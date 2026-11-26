<?php

declare(strict_types=1);

namespace Commerz\Catalog\Collections\Data;

use Commerz\Catalog\Collections\Enums\CollectionKind;
use Commerz\Catalog\Collections\Enums\CollectionStatus;
use Commerz\Measures\LocalizedText;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class CollectionView
{
    public function __construct(
        public CollectionId $id,
        public string $code,
        public LocalizedText $name,
        public ?LocalizedText $description,
        public CollectionKind $kind,
        public ?SmartCollection $smart,
        public CollectionStatus $status,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
