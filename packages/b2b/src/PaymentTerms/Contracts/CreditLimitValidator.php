<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Contracts;

use Commerz\B2b\BusinessUnits\Exceptions\BusinessUnitNotFound;
use Commerz\B2b\PaymentTerms\Data\CreditValidationResult;
use Commerz\B2b\PaymentTerms\Data\ValidateCreditTerms;
use Commerz\B2b\PaymentTerms\Exceptions\CreditAccountFrozen;
use Commerz\B2b\PaymentTerms\Exceptions\CreditAccountNotFound;
use Commerz\B2b\PaymentTerms\Exceptions\CreditLimitExceeded;
use Commerz\B2b\PaymentTerms\Exceptions\RecurringSellingPlanIncompatibleWithPaymentTerms;
use Packman\Attributes\Api;

/**
 * Validates B2B PaymentTerms schedules against credit limits and forbids combining PaymentTerms with recurring SellingPlans.
 */
#[Api(since: '0.1')]
interface CreditLimitValidator
{
    /**
     * @throws RecurringSellingPlanIncompatibleWithPaymentTerms
     * @throws BusinessUnitNotFound|CreditAccountNotFound|CreditAccountFrozen|CreditLimitExceeded
     */
    public function validate(ValidateCreditTerms $command): CreditValidationResult;
}
