<?php

declare(strict_types=1);

namespace Commerz\B2b\BusinessUnits\Exceptions;

use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\BusinessUnits\Enums\BusinessUnitStatus;
use Commerz\B2b\Errors\B2bDomainException;
use Commerz\B2b\Errors\Enums\B2bErrorCode;
use Packman\Attributes\Api;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class BusinessUnitInactive extends B2bDomainException
{
    public function __construct(
        public readonly BusinessUnitId $businessUnitId,
        public readonly BusinessUnitStatus $status,
    ) {
        parent::__construct(
            b2bCode: B2bErrorCode::BusinessUnitInactive,
            errorCategory: ErrorCategory::Conflict,
            message: "Business unit '{$businessUnitId->value}' is {$status->value}.",
            contextDetails: [
                'business_unit_id' => $businessUnitId->value,
                'status' => $status->value,
            ],
        );
    }
}
