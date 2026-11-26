<?php

declare(strict_types=1);

namespace Commerz\B2b\BusinessUnits\Exceptions;

use Commerz\B2b\Errors\B2bDomainException;
use Commerz\B2b\Errors\Enums\B2bErrorCode;
use Packman\Attributes\Api;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class BusinessUnitNotFound extends B2bDomainException
{
    public function __construct(public readonly string $identifier)
    {
        parent::__construct(
            b2bCode: B2bErrorCode::BusinessUnitNotFound,
            errorCategory: ErrorCategory::NotFound,
            message: "Business unit '{$identifier}' was not found.",
            contextDetails: ['entity' => 'business_unit', 'id' => $identifier],
        );
    }
}
