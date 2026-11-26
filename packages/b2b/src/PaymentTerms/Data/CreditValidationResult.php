<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Data;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class CreditValidationResult
{
    public function __construct(
        public PaymentTerms $paymentTerms,
        public PaymentSchedule $schedule,
        public ?CreditAccountView $creditAccount,
    ) {}
}
