<?php

declare(strict_types=1);

namespace Commerz\Shipping\Methods\Events;

use Commerz\Shipping\Methods\Data\MethodId;
use Packman\Attributes\Api;

/** A method was created, updated, had its rates or channels changed, was enabled, disabled or archived in a committed operation. */
#[Api(since: '0.1')]
final readonly class MethodChanged
{
    public function __construct(public MethodId $method) {}
}
