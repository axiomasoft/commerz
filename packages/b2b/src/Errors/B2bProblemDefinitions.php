<?php

declare(strict_types=1);

namespace Commerz\B2b\Errors;

use Commerz\B2b\Errors\Enums\B2bErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Registers RFC 9457 Problem Details definitions for all `axiomasoft/commerz-b2b` error codes.
 */
#[Api(since: '0.1')]
final readonly class B2bProblemDefinitions implements ProblemDefinitionProvider
{
    public function __construct(
        private string $urnPrefix = 'urn:packman:problem:',
    ) {}

    /** @return list<ProblemDefinition> */
    #[Override]
    public function definitions(): array
    {
        return [
            $this->define(B2bErrorCode::ApprovalFlowClosed, 409, 'B2B Approval Flow Closed', ErrorCategory::Conflict, ['approval_flow_id', 'status']),
            $this->define(B2bErrorCode::ApprovalFlowNotFound, 404, 'B2B Approval Flow Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(B2bErrorCode::ApprovalNotGranted, 409, 'B2B Approval Not Granted', ErrorCategory::Conflict, ['approval_flow_id', 'status']),
            $this->define(B2bErrorCode::ApprovalRequired, 409, 'B2B Approval Required', ErrorCategory::Conflict, ['business_unit_id', 'amount_minor', 'currency', 'reasons']),
            $this->define(B2bErrorCode::AssociateNotFound, 404, 'B2B Associate Not Found', ErrorCategory::NotFound, ['business_unit_id', 'customer_ref']),
            $this->define(B2bErrorCode::AssociateUnauthorized, 403, 'B2B Associate Unauthorized', ErrorCategory::Authorization, ['business_unit_id', 'customer_ref', 'reason']),
            $this->define(B2bErrorCode::BusinessUnitInactive, 409, 'B2B Business Unit Inactive', ErrorCategory::Conflict, ['business_unit_id', 'status']),
            $this->define(B2bErrorCode::BusinessUnitNotFound, 404, 'B2B Business Unit Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(B2bErrorCode::CreditAccountFrozen, 409, 'B2B Credit Account Frozen', ErrorCategory::Conflict, ['credit_account_id', 'business_unit_id']),
            $this->define(B2bErrorCode::CreditAccountNotFound, 404, 'B2B Credit Account Not Found', ErrorCategory::NotFound, ['business_unit_id', 'currency']),
            $this->define(B2bErrorCode::CreditHoldNotFound, 404, 'B2B Credit Hold Not Found', ErrorCategory::NotFound, ['reference']),
            $this->define(B2bErrorCode::CreditLimitExceeded, 409, 'B2B Credit Limit Exceeded', ErrorCategory::Conflict, ['business_unit_id', 'requested_minor', 'available_minor', 'credit_limit_minor', 'currency']),
            $this->define(B2bErrorCode::CurrencyMismatch, 422, 'B2B Currency Mismatch', ErrorCategory::Validation, ['expected', 'actual']),
            $this->define(B2bErrorCode::DuplicateBusinessUnitCode, 409, 'Duplicate B2B Business Unit Code', ErrorCategory::Conflict, ['code']),
            $this->define(B2bErrorCode::HierarchyDepthExceeded, 422, 'B2B Hierarchy Depth Exceeded', ErrorCategory::Validation, ['max_depth', 'attempted_depth']),
            $this->define(B2bErrorCode::InvalidPaymentTerms, 422, 'Invalid B2B Payment Terms', ErrorCategory::Validation, ['*']),
            $this->define(B2bErrorCode::InvalidQuoteState, 409, 'Invalid B2B Quote State', ErrorCategory::Conflict, ['quote_id', 'status', 'reason']),
            $this->define(B2bErrorCode::InvalidQuoteValidity, 422, 'Invalid B2B Quote Validity', ErrorCategory::Validation, ['valid_until']),
            $this->define(B2bErrorCode::PaymentTermsNotFound, 404, 'B2B Payment Terms Not Found', ErrorCategory::NotFound, ['code']),
            $this->define(B2bErrorCode::QuoteAlreadyConverted, 409, 'B2B Quote Already Converted', ErrorCategory::Conflict, ['quote_id', 'order_ref']),
            $this->define(B2bErrorCode::QuoteExpired, 409, 'B2B Quote Expired', ErrorCategory::Conflict, ['quote_id', 'valid_until']),
            $this->define(B2bErrorCode::QuoteNotFound, 404, 'B2B Quote Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(B2bErrorCode::RecurringSellingPlanForbidden, 422, 'Recurring SellingPlan Forbidden With PaymentTerms', ErrorCategory::Validation, ['payment_terms_code', 'selling_plan_code']),
        ];
    }

    /** @param list<string> $detailKeys */
    private function define(
        B2bErrorCode $code,
        int $defaultStatus,
        string $title,
        ErrorCategory $category,
        array $detailKeys = [],
    ): ProblemDefinition {
        return new ProblemDefinition(
            code: $code,
            type: $this->urnPrefix.$code->value(),
            defaultStatus: $defaultStatus,
            title: $title,
            retryable: false,
            category: $category,
            detailKeys: $detailKeys,
        );
    }
}
