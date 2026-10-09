<?php

declare(strict_types=1);

namespace Commerz\Pricing\Calculation\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** A tax calculator answered with something the quote cannot use. */
#[Api(since: '0.1')]
final class InvalidTaxResult extends CommerzException
{
    public function __construct(public readonly string $calculator, public readonly string $reason)
    {
        parent::__construct("Tax calculator '{$calculator}' returned an invalid result: {$reason}.");
    }

    public function code(): string
    {
        return 'pricing.invalid_tax_result';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Integration;
    }

    public function context(): array
    {
        return ['calculator' => $this->calculator, 'reason' => $this->reason];
    }
}
