<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Exceptions;

use Commerz\B2b\Errors\B2bDomainException;
use Commerz\B2b\Errors\Enums\B2bErrorCode;
use Packman\Attributes\Api;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class PaymentTermsNotFound extends B2bDomainException
{
    public function __construct(public readonly string $termsCode)
    {
        parent::__construct(
            b2bCode: B2bErrorCode::PaymentTermsNotFound,
            errorCategory: ErrorCategory::NotFound,
            message: "Payment terms '{$termsCode}' were not found.",
            contextDetails: ['code' => $termsCode],
        );
    }
}
