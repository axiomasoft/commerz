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
        // The id is unique per gateway in a varchar(191) index.
        if ($externalId !== null && ($externalId === '' || strlen($externalId) > 191)) {
            throw new InvalidCommand('externalId', 'must be null or 1..191 bytes', self::CODE);
        }

        GatewayMeta::assert($meta, 'meta', self::CODE);
    }
}
