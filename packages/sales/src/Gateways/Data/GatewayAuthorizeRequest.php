<?php

declare(strict_types=1);

namespace Commerz\Sales\Gateways\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Money;
use Commerz\Sales\Gateways\Internal\GatewayMeta;
use Packman\Attributes\Api;

/** Explicit authorization request (separate from capture) sent to an acquiring gateway. */
#[Api(since: '0.1')]
final readonly class GatewayAuthorizeRequest
{
    private const string CODE = 'sales.invalid_gateway_payment';

    /**
     * @param  string  $reference  `session:{id}`: idempotency key at the provider
     * @param  string  $targetRef  `order:{id}` or `topup:{id}`
     * @param  list<GatewayLineItem>  $lineItems  Level 3 line items when supported
     * @param  array<string, scalar>  $meta
     */
    public function __construct(
        public string $reference,
        public string $targetRef,
        public Money $amount,
        public ?string $orderNumber = null,
        public ?CustomerRef $customer = null,
        public ?string $customerEmail = null,
        public array $lineItems = [],
        public array $meta = [],
    ) {
        GatewayMeta::assertReference($reference, 'reference', self::CODE);
        GatewayMeta::assertReference($targetRef, 'targetRef', self::CODE);

        if (! $amount->isPositive()) {
            throw new InvalidCommand('amount', 'must be positive', self::CODE);
        }

        if ($orderNumber !== null && ($orderNumber === '' || strlen($orderNumber) > 191)) {
            throw new InvalidCommand('orderNumber', 'must be null or 1..191 bytes', self::CODE);
        }

        if ($customerEmail !== null && ($customerEmail === '' || strlen($customerEmail) > 191)) {
            throw new InvalidCommand('customerEmail', 'must be null or 1..191 bytes', self::CODE);
        }

        foreach ($lineItems as $item) {
            if (! $item->total->currency->equals($amount->currency)) {
                throw new InvalidCommand('lineItems', 'must match authorization currency', 'sales.currency_mismatch');
            }
        }

        GatewayMeta::assert($meta, 'meta', self::CODE);
    }
}
