<?php

declare(strict_types=1);

namespace Commerz\B2b\Approvals\Exceptions;

use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\Errors\B2bDomainException;
use Commerz\B2b\Errors\Enums\B2bErrorCode;
use Commerz\Money\Money;
use Packman\Attributes\Api;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class ApprovalRequired extends B2bDomainException
{
    /** @param list<string> $reasons */
    public function __construct(
        public readonly BusinessUnitId $businessUnitId,
        public readonly Money $amount,
        public readonly array $reasons,
    ) {
        $reasonList = implode(', ', $reasons);

        parent::__construct(
            b2bCode: B2bErrorCode::ApprovalRequired,
            errorCategory: ErrorCategory::Conflict,
            message: "Order amount {$amount->toDecimal()} {$amount->currency->code} requires approval in business unit '{$businessUnitId->value}' ({$reasonList}).",
            contextDetails: [
                'business_unit_id' => $businessUnitId->value,
                'amount_minor' => $amount->amountMinor,
                'currency' => $amount->currency->code,
                'reasons' => $reasonList,
            ],
        );
    }
}
