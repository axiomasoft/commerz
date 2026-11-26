<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Data;

use Commerz\Sales\Orders\Data\OrderView;
use Packman\Attributes\Api;

/** Result of confirming an OrderChange: confirmed change, updated order, final preview, and compensation outcome. */
#[Api(since: '0.1')]
final readonly class OrderChangeConfirmation
{
    public function __construct(
        public OrderChangeView $change,
        public OrderView $order,
        public OrderChangePreview $preview,
        public CompensationOutcome $compensation,
    ) {}
}
