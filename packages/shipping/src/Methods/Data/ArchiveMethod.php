<?php

declare(strict_types=1);

namespace Commerz\Shipping\Methods\Data;

use Commerz\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ArchiveMethod
{
    public function __construct(public MethodId $id) {}
}
