<?php

declare(strict_types=1);

namespace Commerz\Ports\Stock\Data;

use Commerz\Attributes\Api;
use Commerz\Identity\SellableRef;

#[Api(since: '0.1')]
final readonly class LineAvailability
{
    /** @param  int|null  $available  sellable units free to reserve; null when stock is not limited */
    public function __construct(public SellableRef $sellable, public int $requested, public ?int $available) {}

    public function isSufficient(): bool
    {
        return $this->available === null || $this->available >= $this->requested;
    }
}
