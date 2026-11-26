<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Money\Money;
use Commerz\Sales\Gateways\Data\GatewayLineItem;
use Commerz\Sales\Gateways\Internal\GatewayMeta;
use Packman\Attributes\Api;

/**
 * Authorizes (or auto-captures) a gateway payment session inside a payment collection.
 *
 * When `$amount` is omitted, defaults to the collection's remaining uncovered balance — allowing a stored-value tender
 * to be applied first and the card gateway to cover the exact remainder.
 */
#[Api(since: '0.1')]
final readonly class AuthorizeGatewaySession
{
    private const string CODE = 'sales.invalid_payment';

    /**
     * @param  list<GatewayLineItem>  $lineItems  Level 3 line items (requires gateway `lineItemsL3` capability)
     * @param  array<string, scalar>  $meta
     */
    public function __construct(
        public PaymentCollectionId $collection,
        public string $gateway,
        public ?Money $amount = null,
        public bool $autoCapture = false,
        public array $lineItems = [],
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

        if ($amount !== null) {
            foreach ($lineItems as $item) {
                if (! $item->total->currency->equals($amount->currency)) {
                    throw new InvalidCommand('lineItems', 'must match authorization currency', 'sales.currency_mismatch');
                }
            }
        }

        GatewayMeta::assert($meta, 'meta', self::CODE);
    }
}
