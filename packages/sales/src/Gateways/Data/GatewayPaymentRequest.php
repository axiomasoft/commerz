<?php

declare(strict_types=1);

namespace Commerz\Sales\Gateways\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Money;
use Commerz\Sales\Gateways\Internal\GatewayMeta;

/** One payment attempt the gateway is asked to start. */
#[Api(since: '0.1')]
final readonly class GatewayPaymentRequest
{
    private const string CODE = 'sales.invalid_gateway_payment';

    /**
     * @param  string  $reference  `payment:{id}`: the idempotency key at the provider
     * @param  string  $orderRef  `order:{id}`
     * @param  array<string, scalar>  $meta  what the caller of `InitiatePayment` passed, e.g. `return_url`
     */
    public function __construct(
        public string $reference,
        public string $orderRef,
        public string $orderNumber,
        public Money $amount,
        public ?CustomerRef $customer = null,
        public ?string $customerEmail = null,
        public array $meta = [],
    ) {
        GatewayMeta::assertReference($reference, 'reference', self::CODE);
        GatewayMeta::assertReference($orderRef, 'orderRef', self::CODE);
        GatewayMeta::assertReference($orderNumber, 'orderNumber', self::CODE);

        if (! $amount->isPositive()) {
            throw new InvalidCommand('amount', 'must be positive', self::CODE);
        }

        if ($customerEmail !== null && ($customerEmail === '' || strlen($customerEmail) > 191)) {
            throw new InvalidCommand('customerEmail', 'must be null or 1..191 bytes', self::CODE);
        }

        GatewayMeta::assert($meta, 'meta', self::CODE);
    }
}
