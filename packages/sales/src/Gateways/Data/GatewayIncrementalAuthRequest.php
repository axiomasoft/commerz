<?php

declare(strict_types=1);

namespace Commerz\Sales\Gateways\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Sales\Gateways\Internal\GatewayMeta;
use Packman\Attributes\Api;

/** Request to increase the authorized amount on an existing open authorization. */
#[Api(since: '0.1')]
final readonly class GatewayIncrementalAuthRequest
{
    private const string CODE = 'sales.invalid_gateway_payment';

    /** @param  array<string, scalar>  $meta */
    public function __construct(
        public string $reference,
        public string $paymentReference,
        public ?string $paymentExternalId,
        public Money $additionalAmount,
        public Money $newAuthorizedTotal,
        public array $meta = [],
    ) {
        GatewayMeta::assertReference($reference, 'reference', self::CODE);
        GatewayMeta::assertReference($paymentReference, 'paymentReference', self::CODE);

        if ($paymentExternalId !== null && ($paymentExternalId === '' || strlen($paymentExternalId) > 191)) {
            throw new InvalidCommand('paymentExternalId', 'must be null or 1..191 bytes', self::CODE);
        }

        if (! $additionalAmount->isPositive()) {
            throw new InvalidCommand('additionalAmount', 'must be positive', self::CODE);
        }

        if (! $newAuthorizedTotal->isPositive()) {
            throw new InvalidCommand('newAuthorizedTotal', 'must be positive', self::CODE);
        }

        if (! $newAuthorizedTotal->currency->equals($additionalAmount->currency)) {
            throw new InvalidCommand('newAuthorizedTotal', 'must match additionalAmount currency', 'sales.currency_mismatch');
        }

        if ($newAuthorizedTotal->amountMinor < $additionalAmount->amountMinor) {
            throw new InvalidCommand('newAuthorizedTotal', 'must be at least additionalAmount', self::CODE);
        }

        GatewayMeta::assert($meta, 'meta', self::CODE);
    }
}
