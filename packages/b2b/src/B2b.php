<?php

declare(strict_types=1);

namespace Commerz\B2b;

use Commerz\B2b\Approvals\Contracts\ApprovalFlows;
use Commerz\B2b\BusinessUnits\Contracts\BusinessUnits;
use Commerz\B2b\PaymentTerms\Contracts\CreditAccounts;
use Commerz\B2b\PaymentTerms\Contracts\CreditLimitValidator;
use Commerz\B2b\PaymentTerms\Contracts\PaymentTermsCatalog;
use Commerz\B2b\Quotes\Contracts\B2bQuotes;
use Packman\Attributes\Api;

/**
 * Root of the B2B API: accessors for business units, approval flows, payment terms, credit accounts, credit validator,
 * and 3-stage B2B quotes.
 */
#[Api(since: '0.1')]
final readonly class B2b
{
    public function __construct(
        private BusinessUnits $businessUnits,
        private ApprovalFlows $approvals,
        private PaymentTermsCatalog $paymentTerms,
        private CreditAccounts $creditAccounts,
        private CreditLimitValidator $creditValidator,
        private B2bQuotes $quotes,
    ) {}

    public function businessUnits(): BusinessUnits
    {
        return $this->businessUnits;
    }

    public function approvals(): ApprovalFlows
    {
        return $this->approvals;
    }

    public function paymentTerms(): PaymentTermsCatalog
    {
        return $this->paymentTerms;
    }

    public function creditAccounts(): CreditAccounts
    {
        return $this->creditAccounts;
    }

    public function creditValidator(): CreditLimitValidator
    {
        return $this->creditValidator;
    }

    public function quotes(): B2bQuotes
    {
        return $this->quotes;
    }
}
