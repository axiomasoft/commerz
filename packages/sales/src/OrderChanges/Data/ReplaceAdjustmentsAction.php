<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Data;

use Commerz\Quotes\Data\Adjustment;
use Commerz\Sales\OrderChanges\Enums\OrderChangeActionType;
use Override;
use Packman\Attributes\Api;

/** Replaces line, order, and delivery adjustments on the order with an explicit list. */
#[Api(since: '0.1')]
final readonly class ReplaceAdjustmentsAction implements OrderChangeAction
{
    /** @param  list<Adjustment>  $adjustments */
    public function __construct(
        public array $adjustments,
    ) {}

    #[Override]
    public function type(): OrderChangeActionType
    {
        return OrderChangeActionType::ReplaceAdjustments;
    }

    #[Override]
    public function toPayload(): array
    {
        return [
            'adjustments' => array_map(static fn (Adjustment $a): array => [
                'target' => $a->target->value,
                'line_id' => $a->lineId,
                'amount_minor' => $a->amount->amountMinor,
                'currency' => $a->amount->currency->code,
                'source' => $a->source,
                'explanation' => $a->explanation,
                'promotion_id' => $a->promotionId,
                'coupon_code' => $a->couponCode,
            ], $this->adjustments),
        ];
    }
}
