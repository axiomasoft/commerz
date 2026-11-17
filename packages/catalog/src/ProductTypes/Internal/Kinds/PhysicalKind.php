<?php

declare(strict_types=1);

namespace Commerz\Catalog\ProductTypes\Internal\Kinds;

use Commerz\Catalog\ProductTypes\Contracts\ProductKind;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Packman\Attributes\Internal;

#[Internal]
final readonly class PhysicalKind implements ProductKind
{
    public function key(): string
    {
        return 'physical';
    }

    public function sellableKind(): SellableKind
    {
        return SellableKind::Physical;
    }

    public function requiresShipping(): bool
    {
        return true;
    }
}
