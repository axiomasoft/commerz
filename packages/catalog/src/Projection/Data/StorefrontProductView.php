<?php

declare(strict_types=1);

namespace Commerz\Catalog\Projection\Data;

use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** Single-row storefront read model materialized in `cmz_storefront_products`. */
#[Api(since: '0.1')]
final readonly class StorefrontProductView
{
    /**
     * @param  array<string, mixed>  $productAttributes
     * @param  list<string>  $categories
     * @param  list<string>  $collections
     * @param  list<StorefrontVariantView>  $variants
     * @param  array<string, mixed>  $snapshot  raw decoded JSONB snapshot document
     */
    public function __construct(
        public ChannelId $channel,
        public string $locale,
        public ProductId $product,
        public string $handle,
        public string $name,
        public ?string $description,
        public string $kind,
        public array $productAttributes,
        public array $categories,
        public array $collections,
        public array $variants,
        public ?int $priceMinMinor,
        public ?int $priceMaxMinor,
        public ?string $currency,
        public bool $inStock,
        public array $snapshot,
        public DateTimeImmutable $updatedAt,
    ) {}
}
