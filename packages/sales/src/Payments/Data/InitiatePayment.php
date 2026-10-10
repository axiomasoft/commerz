<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Money\Money;
use Commerz\Sales\Orders\Data\OrderId;

/**
 * Starts a payment attempt of an order at a gateway: `$amount` (the balance by default) must fit in what the order
 * still owes beyond the attempts under way. `$meta` goes to the gateway as is, e.g. `return_url`.
 */
#[Api(since: '0.1')]
final readonly class InitiatePayment
{
    private const string CODE = 'sales.invalid_payment';

    /** @param  array<string, scalar>  $meta */
    public function __construct(
        public OrderId $order,
        public string $gateway,
        public ?Money $amount = null,
        public array $meta = [],
        public ?IdempotencyKey $idempotencyKey = null,
        public ?ActorRef $actor = null,
    ) {
        if (preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/', $gateway) !== 1) {
            throw new InvalidCommand('gateway', 'must be a contribution key of 1..64 characters [a-z0-9_.-]', self::CODE);
        }

        if ($amount !== null && ! $amount->isPositive()) {
            throw new InvalidCommand('amount', 'must be positive', self::CODE);
        }

        self::assertMeta($meta);
    }

    /** @param  array<array-key, mixed>  $meta  checked for callers without static analysis */
    private static function assertMeta(array $meta): void
    {
        foreach ($meta as $key => $value) {
            if (! is_string($key) || $key === '' || ! is_scalar($value)
                || str_contains($key, "\0") || (is_string($value) && str_contains($value, "\0"))
                || json_encode($value) === false) {
                throw new InvalidCommand('meta', 'must map non-empty string keys to JSON scalars without NUL', self::CODE);
            }
        }
    }
}
