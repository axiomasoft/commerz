<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Exceptions;

use Commerz\B2b\Errors\B2bDomainException;
use Commerz\B2b\Errors\Enums\B2bErrorCode;
use Packman\Attributes\Api;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class CreditHoldNotFound extends B2bDomainException
{
    public function __construct(public readonly string $reference)
    {
        parent::__construct(
            b2bCode: B2bErrorCode::CreditHoldNotFound,
            errorCategory: ErrorCategory::NotFound,
            message: "Credit hold for reference '{$reference}' was not found.",
            contextDetails: ['reference' => $reference],
        );
    }
}
