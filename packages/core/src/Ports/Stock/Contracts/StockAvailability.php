<?php

declare(strict_types=1);

namespace Commerz\Ports\Stock\Contracts;

use Commerz\Ports\Stock\Data\AvailabilityAnswer;
use Commerz\Ports\Stock\Data\CheckAvailability;
use Packman\Attributes\Api;

/** Read-only stock answer for a channel. A snapshot, not a promise: only a reservation holds stock. */
#[Api(since: '0.1')]
interface StockAvailability
{
    public function check(CheckAvailability $query): AvailabilityAnswer;
}
