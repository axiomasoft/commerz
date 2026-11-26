<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Data;

use Commerz\Sales\OrderChanges\Enums\OrderChangeActionType;
use Commerz\Sales\Orders\Data\OrderLineId;
use Override;
use Packman\Attributes\Api;

/** Removes an unfulfilled line from the order. */
#[Api(since: '0.1')]
final readonly class RemoveLineAction implements OrderChangeAction
{
    public function __construct(
        public OrderLineId $line,
    ) {}

    #[Override]
    public function type(): OrderChangeActionType
    {
        return OrderChangeActionType::RemoveLine;
    }

    #[Override]
    public function toPayload(): array
    {
        return [
            'line_id' => $this->line->value,
        ];
    }
}
