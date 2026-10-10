<?php

declare(strict_types=1);

namespace Commerz\Sales\Gateways\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Sales\Gateways\Enums\GatewayRefundStatus;
use Commerz\Sales\Gateways\Internal\GatewayMeta;

#[Api(since: '0.1')]
final readonly class GatewayRefundResult
{
    private const string CODE = 'sales.invalid_gateway_refund';

    /** @param  array<string, scalar>  $meta */
    public function __construct(
        public GatewayRefundStatus $status,
        public ?string $externalId = null,
        public array $meta = [],
    ) {
        if ($externalId === '') {
            throw new InvalidCommand('externalId', 'must be null or not empty', self::CODE);
        }

        GatewayMeta::assert($meta, 'meta', self::CODE);
    }
}
