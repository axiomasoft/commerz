<?php

declare(strict_types=1);

namespace Commerz\Pricing\Taxes\Data;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class TaxClassView
{
    public function __construct(public TaxClassId $id, public string $code, public string $name) {}
}
