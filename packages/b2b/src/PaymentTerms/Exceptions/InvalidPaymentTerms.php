<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Exceptions;

use Commerz\B2b\Errors\B2bDomainException;
use Commerz\B2b\Errors\Enums\B2bErrorCode;
use Packman\Attributes\Api;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class InvalidPaymentTerms extends B2bDomainException
{
    public function __construct(string $reason)
    {
        parent::__construct(
            b2bCode: B2bErrorCode::InvalidPaymentTerms,
            errorCategory: ErrorCategory::Validation,
            message: $reason,
            contextDetails: ['reason' => $reason],
            pointerPath: '/payment_terms',
        );
    }
}
