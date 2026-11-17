<?php

declare(strict_types=1);

namespace Commerz\Catalog\ProductTypes\Internal\Kinds;

use Commerz\Catalog\ProductTypes\Contracts\ProductKind;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Packman\Attributes\Internal;

#[Internal]
final readonly class ServiceKind implements ProductKind
{
    public function key(): string
    {
        return 'service';
    }

    public function sellableKind(): SellableKind
    {
        return SellableKind::Service;
    }

    public function requiresShipping(): bool
    {
        return false;
    }
}
