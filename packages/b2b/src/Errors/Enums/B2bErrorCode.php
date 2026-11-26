<?php

declare(strict_types=1);

namespace Commerz\B2b\Errors\Enums;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

/**
 * Stable wire-level error codes emitted by `axiomasoft/commerz-b2b`.
 */
#[Api(since: '0.1')]
enum B2bErrorCode: string implements ErrorCode
{
    case ApprovalFlowClosed = 'b2b.approval_flow_closed';
    case ApprovalFlowNotFound = 'b2b.approval_flow_not_found';
    case ApprovalNotGranted = 'b2b.approval_not_granted';
    case ApprovalRequired = 'b2b.approval_required';
    case AssociateNotFound = 'b2b.associate_not_found';
    case AssociateUnauthorized = 'b2b.associate_unauthorized';
    case BusinessUnitInactive = 'b2b.business_unit_inactive';
    case BusinessUnitNotFound = 'b2b.business_unit_not_found';
    case CreditAccountFrozen = 'b2b.credit_account_frozen';
    case CreditAccountNotFound = 'b2b.credit_account_not_found';
    case CreditHoldNotFound = 'b2b.credit_hold_not_found';
    case CreditLimitExceeded = 'b2b.credit_limit_exceeded';
    case CurrencyMismatch = 'b2b.currency_mismatch';
    case DuplicateBusinessUnitCode = 'b2b.duplicate_business_unit_code';
    case HierarchyDepthExceeded = 'b2b.hierarchy_depth_exceeded';
    case InvalidPaymentTerms = 'b2b.invalid_payment_terms';
    case InvalidQuoteState = 'b2b.invalid_quote_state';
    case InvalidQuoteValidity = 'b2b.invalid_quote_validity';
    case PaymentTermsNotFound = 'b2b.payment_terms_not_found';
    case QuoteAlreadyConverted = 'b2b.quote_already_converted';
    case QuoteExpired = 'b2b.quote_expired';
    case QuoteNotFound = 'b2b.quote_not_found';
    case RecurringSellingPlanForbidden = 'b2b.recurring_selling_plan_forbidden';

    #[Override]
    public function value(): string
    {
        return $this->value;
    }
}
