<?php

declare(strict_types=1);

namespace Commerz\Sales\Refunds\Internal;

use Commerz\Sales\Payments\Data\PaymentId;
use Packman\Attributes\Internal;

/** A captured payment locked for a refund, and what of it can still be refunded, in minor units. */
#[Internal]
final readonly class RefundablePayment
{
    public function __construct(
        public PaymentId $id,
        public string $gateway,
        public string $reference,
        public ?string $externalId,
        public int $refundable,
    ) {}
}
