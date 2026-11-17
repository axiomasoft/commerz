<?php

declare(strict_types=1);

namespace Commerz\Catalog\Data;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class FloatPrice
{
    public function __construct(public float $amount) {}
}
