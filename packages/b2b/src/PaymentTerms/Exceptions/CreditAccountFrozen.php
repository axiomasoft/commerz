<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Exceptions;

use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\Errors\B2bDomainException;
use Commerz\B2b\Errors\Enums\B2bErrorCode;
use Commerz\B2b\PaymentTerms\Data\CreditAccountId;
use Packman\Attributes\Api;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class CreditAccountFrozen extends B2bDomainException
{
    public function __construct(
        public readonly CreditAccountId $creditAccountId,
        public readonly BusinessUnitId $businessUnitId,
    ) {
        parent::__construct(
            b2bCode: B2bErrorCode::CreditAccountFrozen,
            errorCategory: ErrorCategory::Conflict,
            message: "Credit account '{$creditAccountId->value}' for business unit '{$businessUnitId->value}' is frozen.",
            contextDetails: [
                'credit_account_id' => $creditAccountId->value,
                'business_unit_id' => $businessUnitId->value,
            ],
        );
    }
}
