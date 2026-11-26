<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\PickupPointDestination;
use Commerz\Ports\Delivery\Enums\DeliveryMode;
use Packman\Attributes\Api;

/** Delivery method and destination selected for a disjoint subset (group) of cart lines in split delivery. */
#[Api(since: '0.1')]
final readonly class CartDeliveryGroup
{
    /** @var non-empty-list<string> */
    public array $lineIds;

    /** @param  list<string>  $lineIds */
    public function __construct(
        public string $groupKey,
        array $lineIds,
        public string $methodCode,
        public DeliveryMode $mode = DeliveryMode::Shipping,
        public ?Address $shippingAddress = null,
        public ?PickupPointDestination $pickupPoint = null,
    ) {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,63}\z/', $groupKey) !== 1) {
            throw new InvalidCommand('groupKey', 'must match [a-zA-Z0-9][a-zA-Z0-9_.:-]{0,63}', 'sales.invalid_delivery_method');
        }

        if ($methodCode === '' || strlen($methodCode) > 64) {
            throw new InvalidCommand('methodCode', 'must be 1..64 bytes', 'sales.invalid_delivery_method');
        }

        if ($lineIds === []) {
            throw new InvalidCommand('lineIds', 'must contain at least one cart line id', 'sales.invalid_lines');
        }

        $unique = [];

        foreach ($lineIds as $lineId) {
            $trimmed = trim($lineId);

            if ($trimmed === '') {
                throw new InvalidCommand('lineIds', 'line id must not be blank', 'sales.invalid_lines');
            }

            $unique[$trimmed] = $trimmed;
        }

        if ($mode->requiresPickupPoint() && $pickupPoint !== null && $pickupPoint->mode !== $mode) {
            throw new InvalidCommand('pickupPoint', "pickup point mode '{$pickupPoint->mode->value}' does not match '{$mode->value}'", 'sales.invalid_delivery_method');
        }

        if (! $mode->requiresPickupPoint() && $pickupPoint !== null) {
            throw new InvalidCommand('pickupPoint', 'pickupPoint cannot be set when mode is shipping', 'sales.invalid_delivery_method');
        }

        $this->lineIds = array_values($unique);
    }

    /** @param  list<string>  $lineIds */
    public function withLineIds(array $lineIds): self
    {
        return new self(
            groupKey: $this->groupKey,
            lineIds: $lineIds,
            methodCode: $this->methodCode,
            mode: $this->mode,
            shippingAddress: $this->shippingAddress,
            pickupPoint: $this->pickupPoint,
        );
    }
}
