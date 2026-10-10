<?php

declare(strict_types=1);

namespace Commerz\Sales\Checkout\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Commerz\Money\Money;

/** The total of the cart is not the one the buyer accepted; quote it again and show the new total. */
#[Api(since: '0.1')]
final class QuoteChanged extends CommerzException
{
    public function __construct(public readonly Money $expected, public readonly Money $actual)
    {
        parent::__construct("The cart now quotes to {$actual->toDecimal()} {$actual->currency->code}, not the expected {$expected->toDecimal()} {$expected->currency->code}.");
    }

    public function code(): string
    {
        return 'sales.quote_changed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['expected_minor' => $this->expected->amountMinor, 'expected_currency' => $this->expected->currency->code, 'actual_minor' => $this->actual->amountMinor, 'actual_currency' => $this->actual->currency->code];
    }
}
