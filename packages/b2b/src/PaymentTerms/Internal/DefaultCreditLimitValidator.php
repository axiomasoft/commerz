<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Internal;

use Commerz\B2b\PaymentTerms\Contracts\CreditAccounts;
use Commerz\B2b\PaymentTerms\Contracts\CreditLimitValidator;
use Commerz\B2b\PaymentTerms\Data\CreditValidationResult;
use Commerz\B2b\PaymentTerms\Data\ValidateCreditTerms;
use Commerz\B2b\PaymentTerms\Enums\CreditAccountStatus;
use Commerz\B2b\PaymentTerms\Exceptions\CreditAccountFrozen;
use Commerz\B2b\PaymentTerms\Exceptions\CreditAccountNotFound;
use Commerz\B2b\PaymentTerms\Exceptions\CreditLimitExceeded;
use Commerz\B2b\PaymentTerms\Exceptions\RecurringSellingPlanIncompatibleWithPaymentTerms;
use Commerz\Time\Contracts\Clock;
use Override;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DefaultCreditLimitValidator implements CreditLimitValidator
{
    public function __construct(
        private CreditAccounts $creditAccounts,
        private Clock $clock,
    ) {}

    #[Override]
    public function validate(ValidateCreditTerms $command): CreditValidationResult
    {
        foreach ($command->sellingPlans as $plan) {
            if ($plan->isRecurring()) {
                throw new RecurringSellingPlanIncompatibleWithPaymentTerms(
                    paymentTermsCode: $command->paymentTerms->code,
                    sellingPlanCode: $plan->code,
                );
            }
        }

        $issuedAt = $command->issuedAt ?? $this->clock->now();
        $schedule = $command->paymentTerms->schedule($command->orderTotal, $issuedAt);

        if ($schedule->deferredAmount->isZero()) {
            return new CreditValidationResult(
                paymentTerms: $command->paymentTerms,
                schedule: $schedule,
                creditAccount: $this->creditAccounts->findForBusinessUnit($command->businessUnitId, $command->orderTotal->currency),
            );
        }

        $account = $this->creditAccounts->findForBusinessUnit(
            businessUnitId: $command->businessUnitId,
            currency: $command->orderTotal->currency,
            inheritFromAncestors: true,
        ) ?? throw new CreditAccountNotFound($command->businessUnitId, $command->orderTotal->currency);

        if ($account->status !== CreditAccountStatus::Active) {
            throw new CreditAccountFrozen($account->id, $account->businessUnitId);
        }

        if ($schedule->deferredAmount->compare($account->availableCredit) > 0) {
            throw new CreditLimitExceeded(
                businessUnitId: $command->businessUnitId,
                requested: $schedule->deferredAmount,
                available: $account->availableCredit,
                creditLimit: $account->creditLimit,
            );
        }

        return new CreditValidationResult(
            paymentTerms: $command->paymentTerms,
            schedule: $schedule,
            creditAccount: $account,
        );
    }
}
