<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Sales\OrderChanges\Enums\OrderChangeActionType;
use Commerz\Sales\Orders\Data\OrderLineId;
use Override;
use Packman\Attributes\Api;

/** Changes the ordered quantity of an existing line (cannot go below fulfilled + cancelled units). */
#[Api(since: '0.1')]
final readonly class ChangeLineQuantityAction implements OrderChangeAction
{
    private const string CODE = 'sales.invalid_order_change';

    public function __construct(
        public OrderLineId $line,
        public int $quantity,
    ) {
        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1 (use RemoveLineAction to remove a line)', self::CODE);
        }
    }

    #[Override]
    public function type(): OrderChangeActionType
    {
        return OrderChangeActionType::ChangeLineQuantity;
    }

    #[Override]
    public function toPayload(): array
    {
        return [
            'line_id' => $this->line->value,
            'quantity' => $this->quantity,
        ];
    }
}
