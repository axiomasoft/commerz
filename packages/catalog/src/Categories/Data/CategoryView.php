<?php

declare(strict_types=1);

namespace Commerz\Catalog\Categories\Data;

use Commerz\Catalog\Categories\Enums\CategoryStatus;
use Commerz\Measures\LocalizedText;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class CategoryView
{
    public function __construct(
        public CategoryId $id,
        public ?CategoryId $parent,
        public string $code,
        public LocalizedText $name,
        public int $position,
        public CategoryStatus $status,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
