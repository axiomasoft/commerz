<?php

declare(strict_types=1);

namespace Commerz\B2b\Facades;

use Commerz\B2b\Approvals\Contracts\ApprovalFlows;
use Commerz\B2b\BusinessUnits\Contracts\BusinessUnits;
use Commerz\B2b\PaymentTerms\Contracts\CreditAccounts;
use Commerz\B2b\PaymentTerms\Contracts\CreditLimitValidator;
use Commerz\B2b\PaymentTerms\Contracts\PaymentTermsCatalog;
use Commerz\B2b\Quotes\Contracts\B2bQuotes;
use Illuminate\Support\Facades\Facade;
use Packman\Attributes\Api;

/**
 * @method static BusinessUnits businessUnits()
 * @method static ApprovalFlows approvals()
 * @method static PaymentTermsCatalog paymentTerms()
 * @method static CreditAccounts creditAccounts()
 * @method static CreditLimitValidator creditValidator()
 * @method static B2bQuotes quotes()
 *
 * @see \Commerz\B2b\B2b
 */
#[Api(since: '0.1')]
final class B2b extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Commerz\B2b\B2b::class;
    }
}
