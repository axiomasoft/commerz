<?php

declare(strict_types=1);

namespace Commerz\Sales\Gateways\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Sales\Gateways\Internal\GatewayMeta;

/** One refund attempt the gateway is asked to make against a payment. */
#[Api(since: '0.1')]
final readonly class GatewayRefundRequest
{
    private const string CODE = 'sales.invalid_gateway_refund';

    /**
     * @param  string  $reference  `refund:{id}`: the idempotency key at the provider
     * @param  string  $paymentReference  `payment:{id}` of the payment being refunded
     * @param  string|null  $paymentExternalId  the provider's id of that payment, when it is known
     */
    public function __construct(
        public string $reference,
        public string $paymentReference,
        public ?string $paymentExternalId,
        public Money $amount,
        public ?string $reason = null,
    ) {
        GatewayMeta::assertReference($reference, 'reference', self::CODE);
        GatewayMeta::assertReference($paymentReference, 'paymentReference', self::CODE);

        if ($paymentExternalId === '') {
            throw new InvalidCommand('paymentExternalId', 'must be null or not empty', self::CODE);
        }

        if (! $amount->isPositive()) {
            throw new InvalidCommand('amount', 'must be positive', self::CODE);
        }

        if ($reason !== null && strlen($reason) > 191) {
            throw new InvalidCommand('reason', 'must be at most 191 bytes', self::CODE);
        }
    }
}
