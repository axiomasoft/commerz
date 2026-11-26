<?php

declare(strict_types=1);

namespace Commerz\Sales\Gateways\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Sales\Gateways\Enums\GatewayPaymentStatus;
use Commerz\Sales\Gateways\Internal\GatewayMeta;
use Packman\Attributes\Api;

/** Provider response to a {@see GatewayCaptureRequest}. */
#[Api(since: '0.1')]
final readonly class GatewayCaptureResult
{
    private const string CODE = 'sales.invalid_gateway_payment';

    /** @param  array<string, scalar>  $meta */
    public function __construct(
        public GatewayPaymentStatus $status,
        public Money $captured,
        public bool $isFinalCapture = true,
        public ?string $externalId = null,
        public array $meta = [],
    ) {
        if ($captured->isNegative()) {
            throw new InvalidCommand('captured', 'must not be negative', self::CODE);
        }

        if ($externalId !== null && ($externalId === '' || strlen($externalId) > 191)) {
            throw new InvalidCommand('externalId', 'must be null or 1..191 bytes', self::CODE);
        }

        GatewayMeta::assert($meta, 'meta', self::CODE);
    }
}
