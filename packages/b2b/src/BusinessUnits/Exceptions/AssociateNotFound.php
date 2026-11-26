<?php

declare(strict_types=1);

namespace Commerz\B2b\BusinessUnits\Exceptions;

use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\Errors\B2bDomainException;
use Commerz\B2b\Errors\Enums\B2bErrorCode;
use Commerz\Identity\CustomerRef;
use Packman\Attributes\Api;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class AssociateNotFound extends B2bDomainException
{
    public function __construct(
        public readonly BusinessUnitId $businessUnitId,
        public readonly CustomerRef $customer,
    ) {
        $ref = (string) $customer;

        parent::__construct(
            b2bCode: B2bErrorCode::AssociateNotFound,
            errorCategory: ErrorCategory::NotFound,
            message: "Customer '{$ref}' is not an associate of business unit '{$businessUnitId->value}'.",
            contextDetails: [
                'business_unit_id' => $businessUnitId->value,
                'customer_ref' => $ref,
            ],
        );
    }
}
