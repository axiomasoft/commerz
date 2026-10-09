<?php

declare(strict_types=1);

namespace Commerz\Catalog\ProductTypes\Contracts;

use Commerz\Attributes\Spi;
use Commerz\Attributes\SpiKind;
use Commerz\Ports\Sellables\Enums\SellableKind;

/** What kind of thing a product type sells, contributed under kind `product_kind`; stateless, no IO. */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface ProductKind
{
    public const string KIND = 'product_kind';

    public function key(): string;                       // 'physical', 'acme.subscription'

    /** The kind the sellable snapshot reports for variants of this product kind. */
    public function sellableKind(): SellableKind;

    /** Whether a line of this kind needs delivery. */
    public function requiresShipping(): bool;
}
