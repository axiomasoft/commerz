<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Data;

use Packman\Attributes\Api;

/** Asks the gateway of the payment what became of it: the way out of `unknown`, or of an attempt whose process died. */
#[Api(since: '0.1')]
final readonly class ReconcilePayment
{
    public function __construct(public PaymentId $payment) {}
}
