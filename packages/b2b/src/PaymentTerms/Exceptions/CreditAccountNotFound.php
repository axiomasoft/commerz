<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Exceptions;

use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\Errors\B2bDomainException;
use Commerz\B2b\Errors\Enums\B2bErrorCode;
use Commerz\Money\Currency;
use Packman\Attributes\Api;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class CreditAccountNotFound extends B2bDomainException
{
    public function __construct(
        public readonly BusinessUnitId $businessUnitId,
        public readonly Currency $currency,
    ) {
        parent::__construct(
            b2bCode: B2bErrorCode::CreditAccountNotFound,
            errorCategory: ErrorCategory::NotFound,
            message: "No {$currency->code} credit account is configured for business unit '{$businessUnitId->value}'.",
            contextDetails: [
                'business_unit_id' => $businessUnitId->value,
                'currency' => $currency->code,
            ],
        );
    }
}
