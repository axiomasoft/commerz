<?php

declare(strict_types=1);

namespace Commerz\Sales\Gateways\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Sales\Gateways\Internal\GatewayMeta;
use Packman\Attributes\Api;

/** Request to void (release) an uncaptured authorization at an acquiring gateway. */
#[Api(since: '0.1')]
final readonly class GatewayVoidRequest
{
    private const string CODE = 'sales.invalid_gateway_payment';

    /** @param  array<string, scalar>  $meta */
    public function __construct(
        public string $reference,
        public string $paymentReference,
        public ?string $paymentExternalId,
        public ?string $reason = null,
        public array $meta = [],
    ) {
        GatewayMeta::assertReference($reference, 'reference', self::CODE);
        GatewayMeta::assertReference($paymentReference, 'paymentReference', self::CODE);

        if ($paymentExternalId !== null && ($paymentExternalId === '' || strlen($paymentExternalId) > 191)) {
            throw new InvalidCommand('paymentExternalId', 'must be null or 1..191 bytes', self::CODE);
        }

        if ($reason !== null && ($reason === '' || strlen($reason) > 191)) {
            throw new InvalidCommand('reason', 'must be null or 1..191 bytes', self::CODE);
        }

        GatewayMeta::assert($meta, 'meta', self::CODE);
    }
}
