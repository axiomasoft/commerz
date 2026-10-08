<?php

declare(strict_types=1);

namespace Commerz\Ports\Stock\Data;

use Commerz\Attributes\Api;
use Commerz\Identity\SellableRef;

#[Api(since: '0.1')]
final readonly class AvailabilityAnswer
{
    /** @param  list<LineAvailability>  $lines  in the order of the query */
    public function __construct(public array $lines) {}

    public function isSatisfiable(): bool
    {
        return array_all($this->lines, static fn (LineAvailability $line): bool => $line->isSufficient());
    }

    public function line(SellableRef $sellable): ?LineAvailability
    {
        return array_find($this->lines, static fn (LineAvailability $line): bool => $line->sellable->equals($sellable));
    }
}
