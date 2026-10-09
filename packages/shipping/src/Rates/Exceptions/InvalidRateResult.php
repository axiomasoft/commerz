<?php

declare(strict_types=1);

namespace Commerz\Shipping\Rates\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** A delivery rate answered with something the package cannot trust, e.g. a price in another currency. */
#[Api(since: '0.1')]
final class InvalidRateResult extends CommerzException
{
    public function __construct(public readonly string $rate, public readonly string $reason)
    {
        parent::__construct("Rate '{$rate}' returned an invalid result: {$reason}.");
    }

    public function code(): string
    {
        return 'shipping.invalid_rate_result';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Integration;
    }

    public function context(): array
    {
        return ['rate' => $this->rate, 'reason' => $this->reason];
    }
}
