<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Lifecycle\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Packman\Attributes\Api;

/**
 * Line item input when creating a {@see SubscriptionContract}.
 */
#[Api(since: '0.1')]
final readonly class SubscriptionLineInput
{
    public function __construct(
        public string $lineId,
        public SellableSnapshot $sellable,
        public int $quantity,
        public ?Money $unitPriceOverride = null,
    ) {
        if (preg_match('/\A[A-Za-z0-9_.:\-]{1,64}\z/', $lineId) !== 1) {
            throw new InvalidCommand('lineId', "'{$lineId}' must match [A-Za-z0-9_.:-]{1,64}", 'subscriptions.invalid_command');
        }

        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'subscriptions.invalid_command');
        }

        if ($unitPriceOverride !== null && $unitPriceOverride->isNegative()) {
            throw new InvalidCommand('unitPriceOverride', 'must not be negative', 'subscriptions.invalid_command');
        }

        $sellable->quantityStepPolicy?->assertValid($quantity);
    }
}
