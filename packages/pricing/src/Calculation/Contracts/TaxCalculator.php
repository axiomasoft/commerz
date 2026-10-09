<?php

declare(strict_types=1);

namespace Commerz\Pricing\Calculation\Contracts;

use Commerz\Attributes\Spi;
use Commerz\Attributes\SpiKind;
use Commerz\Errors\CommerzException;
use Commerz\Pricing\Calculation\Data\TaxRequest;
use Commerz\Pricing\Calculation\Data\TaxResult;

/**
 * Computes the tax on the discounted base of every line and of delivery. The built-in `rates` calculator reads the
 * stored rates; a plugin contributes its own (an external tax engine, compound or origin-based rules).
 *
 * The quote stage does not trust the answer: every amount must refer to a requested item, be in the context currency
 * and, for a tax included in the price, stay within the item's base (`InvalidTaxResult` otherwise).
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface TaxCalculator
{
    public const string KIND = 'tax_calculator';

    /** Contribution key, e.g. `rates` or `acme.avalara`. */
    public function key(): string;

    /**
     * Does not write and makes no network call inside the caller's transaction; time comes from the context.
     *
     * @throws CommerzException when no tax can be determined; the quote stage reports it as a failed quote
     */
    public function calculate(TaxRequest $request): TaxResult;
}
