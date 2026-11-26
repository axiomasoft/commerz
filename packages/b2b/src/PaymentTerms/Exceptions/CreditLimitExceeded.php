<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Exceptions;

use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\Errors\B2bDomainException;
use Commerz\B2b\Errors\Enums\B2bErrorCode;
use Commerz\Money\Money;
use Packman\Attributes\Api;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class CreditLimitExceeded extends B2bDomainException
{
    public function __construct(
        public readonly BusinessUnitId $businessUnitId,
        public readonly Money $requested,
        public readonly Money $available,
        public readonly Money $creditLimit,
    ) {
        parent::__construct(
            b2bCode: B2bErrorCode::CreditLimitExceeded,
            errorCategory: ErrorCategory::Conflict,
            message: "Requested deferred credit {$requested->toDecimal()} {$requested->currency->code} exceeds available credit {$available->toDecimal()} {$available->currency->code} (limit {$creditLimit->toDecimal()}).",
            contextDetails: [
                'business_unit_id' => $businessUnitId->value,
                'requested_minor' => $requested->amountMinor,
                'available_minor' => $available->amountMinor,
                'credit_limit_minor' => $creditLimit->amountMinor,
                'currency' => $requested->currency->code,
            ],
        );
    }
}
