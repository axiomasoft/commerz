<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Data;

use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\PaymentTerms\Enums\CreditAccountStatus;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ConfigureCreditAccount
{
    public function __construct(
        public BusinessUnitId $businessUnitId,
        public Money $creditLimit,
        public CreditAccountStatus $status = CreditAccountStatus::Active,
    ) {
        if ($creditLimit->isNegative()) {
            throw new InvalidCommand('creditLimit', 'must not be negative', 'b2b.invalid_credit_limit');
        }
    }
}
