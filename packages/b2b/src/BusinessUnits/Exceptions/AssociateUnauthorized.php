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
final class AssociateUnauthorized extends B2bDomainException
{
    public function __construct(
        public readonly BusinessUnitId $businessUnitId,
        public readonly CustomerRef $customer,
        public readonly string $reason,
    ) {
        $ref = (string) $customer;

        parent::__construct(
            b2bCode: B2bErrorCode::AssociateUnauthorized,
            errorCategory: ErrorCategory::Authorization,
            message: "Associate '{$ref}' is not authorized in business unit '{$businessUnitId->value}': {$reason}.",
            contextDetails: [
                'business_unit_id' => $businessUnitId->value,
                'customer_ref' => $ref,
                'reason' => $reason,
            ],
        );
    }
}
