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
 * Captures funds against an open payment session.
 *
 * Supports multicapture: pass `isFinalCapture: false` for a non-final partial capture (keeping the session open for
 * further captures), then `isFinalCapture: true` on the final capture to close the authorization.
 */
#[Api(since: '0.1')]
final readonly class CapturePaymentSession
{
    private const string CODE = 'sales.invalid_payment';

    /**
     * @param  list<GatewayLineItem>  $lineItems
     * @param  array<string, scalar>  $meta
     */
    public function __construct(
        public PaymentSessionId $session,
        public ?Money $amount = null,
        public bool $isFinalCapture = true,
        public array $lineItems = [],
        public array $meta = [],
        public ?IdempotencyKey $idempotencyKey = null,
        public ?ActorRef $actor = null,
    ) {
        if ($amount !== null && ! $amount->isPositive()) {
            throw new InvalidCommand('amount', 'must be positive', self::CODE);
        }

        if ($amount !== null) {
            foreach ($lineItems as $item) {
                if (! $item->total->currency->equals($amount->currency)) {
                    throw new InvalidCommand('lineItems', 'must match capture currency', 'sales.currency_mismatch');
                }
            }
        }

        GatewayMeta::assert($meta, 'meta', self::CODE);
    }
}
