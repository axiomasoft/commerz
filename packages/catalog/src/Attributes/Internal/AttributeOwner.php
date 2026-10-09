<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Internal;

use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\ProductTypes\Enums\AttributeLevel;
use Commerz\Identity\EntityId;

/** The row whose attribute document the writer changes: a product, or a variant of a product. */
final readonly class AttributeOwner
{
    private function __construct(
        public AttributeLevel $level,
        public string $product,
        public ?string $variant,
    ) {}

    public static function product(ProductId $product): self
    {
        return new self(AttributeLevel::Product, $product->value, null);
    }

    public static function variant(EntityId $variant, ProductId $product): self
    {
        return new self(AttributeLevel::Variant, $product->value, $variant->value);
    }

    public function id(): string
    {
        return $this->variant ?? $this->product;
    }

    /** Logical name of the table that holds the document. */
    public function table(): string
    {
        return $this->variant === null ? 'products' : 'variants';
    }
}
