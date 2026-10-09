<?php

declare(strict_types=1);

namespace Commerz\Catalog\ProductTypes\Data;

use Commerz\Attributes\Api;
use Commerz\Catalog\ProductTypes\Enums\ProductTypeStatus;
use Commerz\Measures\LocalizedText;
use DateTimeImmutable;

#[Api(since: '0.1')]
final readonly class ProductTypeView
{
    /**
     * @param  list<AttributeSlotView>  $slots  product level first, then variant level, each by position
     * @param  list<string>  $axes  option codes by position
     */
    public function __construct(
        public ProductTypeId $id,
        public string $code,
        public LocalizedText $name,
        public string $kind,
        public ProductTypeStatus $status,
        public array $slots,
        public array $axes,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
