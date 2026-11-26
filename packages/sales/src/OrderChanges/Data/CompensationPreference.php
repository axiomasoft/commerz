<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Sales\OrderChanges\Enums\NegativeDeltaStrategy;
use Commerz\Sales\OrderChanges\Enums\PositiveDeltaStrategy;
use Commerz\Sales\Payments\Data\PaymentId;
use Packman\Attributes\Api;

/**
 * Caller preference for financial compensation when confirming an OrderChange with a non-zero `amountDelta`.
 */
#[Api(since: '0.1')]
final readonly class CompensationPreference
{
    private const string CODE = 'sales.invalid_order_change';

    /** @param  array<string, scalar>  $paymentMeta */
    public function __construct(
        public PositiveDeltaStrategy $onIncrease = PositiveDeltaStrategy::DeltaCharge,
        public NegativeDeltaStrategy $onDecrease = NegativeDeltaStrategy::PartialRefund,
        public ?string $paymentGateway = null,
        public ?PaymentId $paymentId = null,
        public ?string $storedValueAccountId = null,
        public array $paymentMeta = [],
        public ?string $reason = null,
    ) {
        if ($paymentGateway !== null && preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/', $paymentGateway) !== 1) {
            throw new InvalidCommand('paymentGateway', 'must be null or 1..64 characters [a-z0-9_.-]', self::CODE);
        }

        if ($storedValueAccountId !== null && trim($storedValueAccountId) === '') {
            throw new InvalidCommand('storedValueAccountId', 'must be null or non-empty', self::CODE);
        }

        if ($reason !== null && ($reason === '' || strlen($reason) > 191 || ! mb_check_encoding($reason, 'UTF-8') || str_contains($reason, "\0"))) {
            throw new InvalidCommand('reason', 'must be null or 1..191 bytes of UTF-8 without NUL', self::CODE);
        }

        self::assertMeta($paymentMeta);
    }

    /** @param  array<array-key, mixed>  $meta */
    private static function assertMeta(array $meta): void
    {
        foreach ($meta as $key => $value) {
            if (! is_string($key) || $key === '' || ! is_scalar($value)
                || str_contains($key, "\0") || (is_string($value) && str_contains($value, "\0"))
                || json_encode($value) === false) {
                throw new InvalidCommand('paymentMeta', 'must map non-empty string keys to JSON scalars without NUL', self::CODE);
            }
        }
    }
}
