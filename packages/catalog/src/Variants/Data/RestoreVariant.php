<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Data;

use Packman\Attributes\Api;

/** Makes an archived variant active again; restoring an active variant changes nothing. */
#[Api(since: '0.1')]
final readonly class RestoreVariant
{
    public function __construct(public VariantId $variant) {}
}
