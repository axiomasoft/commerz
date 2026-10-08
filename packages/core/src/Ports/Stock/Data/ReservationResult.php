<?php

declare(strict_types=1);

namespace Commerz\Ports\Stock\Data;

use Commerz\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ReservationResult
{
    /** @param  list<ReservedLine>  $lines */
    public function __construct(public string $demand, public array $lines) {}
}
