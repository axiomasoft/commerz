<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Contracts;

use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\BusinessUnits\Exceptions\BusinessUnitNotFound;
use Commerz\B2b\PaymentTerms\Data\CaptureCredit;
use Commerz\B2b\PaymentTerms\Data\ConfigureCreditAccount;
use Commerz\B2b\PaymentTerms\Data\CreditAccountView;
use Commerz\B2b\PaymentTerms\Data\CreditHoldView;
use Commerz\B2b\PaymentTerms\Data\ReleaseCredit;
use Commerz\B2b\PaymentTerms\Data\ReserveCredit;
use Commerz\B2b\PaymentTerms\Exceptions\CreditAccountFrozen;
use Commerz\B2b\PaymentTerms\Exceptions\CreditAccountNotFound;
use Commerz\B2b\PaymentTerms\Exceptions\CreditHoldNotFound;
use Commerz\B2b\PaymentTerms\Exceptions\CreditLimitExceeded;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Packman\Attributes\Api;

/**
 * Manages B2B credit accounts and atomic credit holds across the BusinessUnit hierarchy (`INV-B2B-CREDIT-001`).
 */
#[Api(since: '0.1')]
interface CreditAccounts
{
    /** @throws BusinessUnitNotFound|CreditLimitExceeded */
    public function configure(ConfigureCreditAccount $command): CreditAccountView;

    /**
     * Resolves the credit account for `$businessUnitId` in `$currency`, walking up ancestors when `$inheritFromAncestors`
     * is true so divisions can draw from a parent corporate credit pool.
     *
     * @throws BusinessUnitNotFound
     */
    public function findForBusinessUnit(BusinessUnitId $businessUnitId, Currency $currency, bool $inheritFromAncestors = true): ?CreditAccountView;

    /**
     * Atomically reserves deferred credit exposure for an order or quote conversion.
     *
     * @throws BusinessUnitNotFound|CreditAccountNotFound|CreditAccountFrozen|CreditLimitExceeded
     */
    public function reserve(ReserveCredit $command): CreditHoldView;

    /**
     * Captures a reserved credit hold into `usedCredit` (e.g. when invoiced).
     *
     * @throws CreditHoldNotFound
     */
    public function capture(CaptureCredit $command): CreditAccountView;

    /**
     * Releases an uncaptured credit hold back to available credit (e.g. on cancellation).
     *
     * @throws CreditHoldNotFound
     */
    public function release(ReleaseCredit $command): CreditAccountView;

    /**
     * Records a repayment against `usedCredit` when an invoice is settled.
     *
     * @throws BusinessUnitNotFound|CreditAccountNotFound
     */
    public function repay(BusinessUnitId $businessUnitId, Money $amount): CreditAccountView;
}
