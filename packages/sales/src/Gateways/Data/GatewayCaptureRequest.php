<?php

declare(strict_types=1);

namespace Commerz\Sales\Gateways\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Sales\Gateways\Internal\GatewayMeta;
use Packman\Attributes\Api;

/**
 * Capture request against an existing authorization.
 *
 * When `$isFinalCapture` is `false` (multicapture), the provider keeps any remaining authorization open for subsequent
 * captures. When `true`, this capture closes the authorization.
 */
#[Api(since: '0.1')]
final readonly class GatewayCaptureRequest
{
    private const string CODE = 'sales.invalid_gateway_payment';

    /**
     * @param  string  $reference  `capture:{id}`: idempotency key for this capture
     * @param  string  $paymentReference  reference of the authorized session/payment
     * @param  string|null  $paymentExternalId  provider id of the authorization, once known
     * @param  list<GatewayLineItem>  $lineItems  Level 3 line items for this capture
     * @param  array<string, scalar>  $meta
     */
    public function __construct(
        public string $reference,
        public string $paymentReference,
        public ?string $paymentExternalId,
        public Money $amount,
        public bool $isFinalCapture = true,
        public array $lineItems = [],
        public array $meta = [],
    ) {
        GatewayMeta::assertReference($reference, 'reference', self::CODE);
        GatewayMeta::assertReference($paymentReference, 'paymentReference', self::CODE);

        if ($paymentExternalId !== null && ($paymentExternalId === '' || strlen($paymentExternalId) > 191)) {
            throw new InvalidCommand('paymentExternalId', 'must be null or 1..191 bytes', self::CODE);
        }

        if (! $amount->isPositive()) {
            throw new InvalidCommand('amount', 'must be positive', self::CODE);
        }

        foreach ($lineItems as $item) {
            if (! $item->total->currency->equals($amount->currency)) {
                throw new InvalidCommand('lineItems', 'must match capture currency', 'sales.currency_mismatch');
            }
        }

        GatewayMeta::assert($meta, 'meta', self::CODE);
    }
}
