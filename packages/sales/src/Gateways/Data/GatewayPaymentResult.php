<?php

declare(strict_types=1);

namespace Commerz\Sales\Gateways\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Sales\Gateways\Enums\GatewayPaymentStatus;
use Commerz\Sales\Gateways\Internal\GatewayMeta;

/**
 * What the gateway knows about a payment attempt. The DTO checks its own shape; whether the result fits the attempt
 * (currency, amounts within the request, a required `actionUrl`) is checked by the sales workflow, which treats a
 * violation as an unknown outcome.
 */
#[Api(since: '0.1')]
final readonly class GatewayPaymentResult
{
    private const string CODE = 'sales.invalid_gateway_payment';

    /**
     * @param  string|null  $externalId  the provider's id of the payment
     * @param  Money|null  $authorized  amount held, when the provider reports it
     * @param  Money|null  $captured  amount taken, when the provider reports it
     * @param  string|null  $actionUrl  where the customer must go to continue (`RequiresAction`)
     * @param  array<string, scalar>  $meta
     */
    public function __construct(
        public GatewayPaymentStatus $status,
        public ?string $externalId = null,
        public ?Money $authorized = null,
        public ?Money $captured = null,
        public ?string $actionUrl = null,
        public array $meta = [],
    ) {
        // The id is unique per gateway in a varchar(191) index.
        if ($externalId !== null && ($externalId === '' || strlen($externalId) > 191)) {
            throw new InvalidCommand('externalId', 'must be null or 1..191 bytes', self::CODE);
        }

        foreach (['authorized' => $authorized, 'captured' => $captured] as $field => $amount) {
            if ($amount !== null && $amount->isNegative()) {
                throw new InvalidCommand($field, 'must not be negative', self::CODE);
            }
        }

        if ($actionUrl === '') {
            throw new InvalidCommand('actionUrl', 'must be null or not empty', self::CODE);
        }

        GatewayMeta::assert($meta, 'meta', self::CODE);
    }
}
