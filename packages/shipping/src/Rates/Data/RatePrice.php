<?php

declare(strict_types=1);

namespace Commerz\Shipping\Rates\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;

/** The answer of a rate: an amount of zero or more, or "cannot deliver this" with a snake_case reason. */
#[Api(since: '0.1')]
final readonly class RatePrice
{
    private function __construct(
        public ?Money $amount,
        public ?string $reason,
        public ?string $message,
    ) {}

    public static function of(Money $amount): self
    {
        if ($amount->isNegative()) {
            throw new InvalidCommand('amount', 'must not be negative', 'shipping.invalid_rate_price');
        }

        return new self($amount, null, null);
    }

    public static function unavailable(string $reason, string $message): self
    {
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $reason) !== 1) {
            throw new InvalidCommand('reason', "'{$reason}' must be snake_case", 'shipping.invalid_rate_price');
        }

        return new self(null, $reason, $message);
    }

    public function isAvailable(): bool
    {
        return $this->amount !== null;
    }
}
