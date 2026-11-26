<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Exceptions;

use Commerz\B2b\Errors\B2bDomainException;
use Commerz\B2b\Errors\Enums\B2bErrorCode;
use Packman\Attributes\Api;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class RecurringSellingPlanIncompatibleWithPaymentTerms extends B2bDomainException
{
    public function __construct(
        public readonly string $paymentTermsCode,
        public readonly string $sellingPlanCode,
    ) {
        parent::__construct(
            b2bCode: B2bErrorCode::RecurringSellingPlanForbidden,
            errorCategory: ErrorCategory::Validation,
            message: "PaymentTerms '{$paymentTermsCode}' cannot be combined with recurring SellingPlan '{$sellingPlanCode}'.",
            contextDetails: [
                'payment_terms_code' => $paymentTermsCode,
                'selling_plan_code' => $sellingPlanCode,
            ],
            pointerPath: '/payment_terms',
        );
    }
}
