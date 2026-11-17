<?php

declare(strict_types=1);

namespace Commerz\Sales\Refunds\Data;

use Packman\Attributes\Api;

/** Asks the gateway of the refund what became of it: the way out of `unknown`, or of an attempt whose process died. */
#[Api(since: '0.1')]
final readonly class ReconcileRefund
{
    public function __construct(public RefundId $refund) {}
}
