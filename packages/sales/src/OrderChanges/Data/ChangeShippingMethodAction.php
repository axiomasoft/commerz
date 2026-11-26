<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Quotes\Data\DeliveryCharge;
use Commerz\Sales\OrderChanges\Enums\OrderChangeActionType;
use Override;
use Packman\Attributes\Api;

/** Changes or clears the order's shipping method (priced by the delivery stage unless `$chargeOverride` is supplied). */
#[Api(since: '0.1')]
final readonly class ChangeShippingMethodAction implements OrderChangeAction
{
    private const string CODE = 'sales.invalid_order_change';

    public function __construct(
        public ?string $methodCode,
        public ?DeliveryCharge $chargeOverride = null,
    ) {
        if ($methodCode !== null && preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/', $methodCode) !== 1) {
            throw new InvalidCommand('methodCode', 'must be null or 1..64 characters [a-z0-9_.-]', self::CODE);
        }

        if ($methodCode === null && $chargeOverride !== null) {
            throw new InvalidCommand('chargeOverride', 'cannot override delivery charge when clearing shipping method', self::CODE);
        }

        if ($chargeOverride !== null && $chargeOverride->methodCode !== $methodCode) {
            throw new InvalidCommand('chargeOverride', 'chargeOverride methodCode must match methodCode', self::CODE);
        }
    }

    #[Override]
    public function type(): OrderChangeActionType
    {
        return OrderChangeActionType::ChangeShippingMethod;
    }

    #[Override]
    public function toPayload(): array
    {
        return [
            'method_code' => $this->methodCode,
            'charge_override' => $this->chargeOverride === null ? null : [
                'method_code' => $this->chargeOverride->methodCode,
                'name' => $this->chargeOverride->name,
                'carrier' => $this->chargeOverride->carrier,
                'price_minor' => $this->chargeOverride->price->amountMinor,
                'currency' => $this->chargeOverride->price->currency->code,
                'tax_included' => $this->chargeOverride->taxIncluded,
            ],
        ];
    }
}
