<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Sales\OrderChanges\Enums\OrderChangeActionType;
use Commerz\Sales\Orders\Data\OrderLineId;
use Override;
use Packman\Attributes\Api;

/** Overrides the snapshotted unit price of an existing line. */
#[Api(since: '0.1')]
final readonly class OverrideUnitPriceAction implements OrderChangeAction
{
    private const string CODE = 'sales.invalid_order_change';

    public function __construct(
        public OrderLineId $line,
        public Money $unitPrice,
        public ?string $reason = null,
    ) {
        if ($unitPrice->isNegative()) {
            throw new InvalidCommand('unitPrice', 'must not be negative', self::CODE);
        }

        if ($reason !== null && ($reason === '' || strlen($reason) > 255 || ! mb_check_encoding($reason, 'UTF-8') || str_contains($reason, "\0"))) {
            throw new InvalidCommand('reason', 'must be null or 1..255 bytes of UTF-8 without NUL', self::CODE);
        }
    }

    #[Override]
    public function type(): OrderChangeActionType
    {
        return OrderChangeActionType::OverrideUnitPrice;
    }

    #[Override]
    public function toPayload(): array
    {
        return [
            'line_id' => $this->line->value,
            'unit_price' => [
                'amount_minor' => $this->unitPrice->amountMinor,
                'currency' => $this->unitPrice->currency->code,
            ],
            'reason' => $this->reason,
        ];
    }
}
