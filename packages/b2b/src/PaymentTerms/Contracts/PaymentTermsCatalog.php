<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Contracts;

use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\BusinessUnits\Exceptions\BusinessUnitNotFound;
use Commerz\B2b\PaymentTerms\Data\PaymentTerms;
use Commerz\B2b\PaymentTerms\Exceptions\PaymentTermsNotFound;
use Packman\Attributes\Api;

/**
 * Resolves built-in (`net_15`, `net_30`, `net_60`) and custom persisted B2B payment terms (including deposit schedules).
 */
#[Api(since: '0.1')]
interface PaymentTermsCatalog
{
    /** @throws BusinessUnitNotFound when `$businessUnitId` is provided and does not exist */
    public function save(PaymentTerms $terms, ?BusinessUnitId $businessUnitId = null): PaymentTerms;

    public function find(string $code): ?PaymentTerms;

    /** @throws PaymentTermsNotFound */
    public function get(string $code): PaymentTerms;
}
