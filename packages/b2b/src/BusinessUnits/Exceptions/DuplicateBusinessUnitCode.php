<?php

declare(strict_types=1);

namespace Commerz\B2b\BusinessUnits\Exceptions;

use Commerz\B2b\Errors\B2bDomainException;
use Commerz\B2b\Errors\Enums\B2bErrorCode;
use Packman\Attributes\Api;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class DuplicateBusinessUnitCode extends B2bDomainException
{
    public function __construct(public readonly string $unitCode)
    {
        parent::__construct(
            b2bCode: B2bErrorCode::DuplicateBusinessUnitCode,
            errorCategory: ErrorCategory::Conflict,
            message: "Business unit code '{$unitCode}' is already in use.",
            contextDetails: ['code' => $unitCode],
            pointerPath: '/code',
        );
    }
}
