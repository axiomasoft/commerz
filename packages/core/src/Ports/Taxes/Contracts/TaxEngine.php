<?php

declare(strict_types=1);

namespace Commerz\Ports\Taxes\Contracts;

use Commerz\Errors\CommerzException;
use Commerz\Ports\Taxes\Data\CalculatedTax;
use Commerz\Ports\Taxes\Data\CalculateTax;
use Packman\Attributes\Api;

/**
 * Read-only port evaluating taxes, exemptions, and B2B VAT reverse charge for taxable lines and delivery.
 * Implemented by `axiomasoft/commerz-pricing`; defaults to `NullTaxEngine` when pricing is not installed.
 */
#[Api(since: '0.1')]
interface TaxEngine
{
    /**
     * Evaluates taxes without side effects or database mutations.
     *
     * @throws CommerzException
     */
    public function calculate(CalculateTax $request): CalculatedTax;
}
