<?php

declare(strict_types=1);

namespace Commerz\Pricing\Calculation\Internal;

use Commerz\Money\Money;
use Commerz\Money\MoneyAllocator;
use Commerz\Money\Rate;
use Commerz\Money\RoundingMode;
use Commerz\Pricing\Calculation\Contracts\TaxCalculator;
use Commerz\Pricing\Calculation\Data\TaxableItem;
use Commerz\Pricing\Calculation\Data\TaxAmount;
use Commerz\Pricing\Calculation\Data\TaxRequest;
use Commerz\Pricing\Calculation\Data\TaxResult;
use Commerz\Pricing\Configuration\PricingOptions;
use Commerz\Pricing\Taxes\Exceptions\TaxRateNotFound;
use Commerz\Pricing\Taxes\Exceptions\TaxZoneNotFound;
use Commerz\Pricing\Taxes\Internal\TaxRateLookup;
use Commerz\Pricing\Taxes\Internal\TaxZoneLookup;
use Commerz\Schema\Rows;
use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Internal;

/**
 * The built-in calculator: the zone of the delivery address (else `default_zone`) and the stored rates in force at the
 * moment of the context. Several tax codes of one class are separate taxes on the same base. A class without a rate
 * in the zone is an error, never a silent zero; a zero rate is set explicitly.
 */
#[Internal]
final readonly class RatesTaxCalculator implements TaxCalculator
{
    public const string KEY = 'rates';

    public function __construct(
        private TaxZoneLookup $zones,
        private TaxRateLookup $rates,
        private Container $container,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function calculate(TaxRequest $request): TaxResult
    {
        $options = $this->container->make(PricingOptions::class);
        $zone = $this->zoneOf($request, $options->defaultTaxZone);

        // Outside every tax zone nothing is taxed (an export, for one).
        if ($zone === null) {
            return new TaxResult([]);
        }

        $zoneId = Rows::string($zone, 'id');
        $classes = array_values(array_unique(array_map(static fn (TaxableItem $item): string => $item->taxClass, $request->items)));
        $inForce = $this->rates->inForce($zoneId, $classes, $request->context->at);
        $amounts = [];

        foreach ($request->items as $item) {
            $rates = $inForce[$item->taxClass]
                ?? throw TaxRateNotFound::forClass($item->taxClass, Rows::string($zone, 'code'), $request->context->at->format(DATE_ATOM));

            foreach ($this->taxesOf($item, $rates, $options->taxRounding) as $amount) {
                $amounts[] = $amount;
            }
        }

        return new TaxResult($amounts);
    }

    private function zoneOf(TaxRequest $request, ?string $defaultZone): ?object
    {
        if ($request->destination !== null) {
            return $this->zones->forAddress($request->destination);
        }

        if ($defaultZone === null) {
            return null;
        }

        return $this->zones->byCode($defaultZone) ?? throw new TaxZoneNotFound($defaultZone);
    }

    /**
     * @param  list<array{code: string, rate: Rate}>  $rates  sorted by tax code, which fixes who gets the odd minor unit
     * @return list<TaxAmount>
     */
    private function taxesOf(TaxableItem $item, array $rates, RoundingMode $rounding): array
    {
        if (! $item->included) {
            return array_map(
                static fn (array $tax): TaxAmount => new TaxAmount($item->target, $item->lineId, $tax['code'], $tax['rate'], $item->base->percentage($tax['rate'], $rounding)),
                $rates,
            );
        }

        // The price holds the tax: take it out at the sum of the rates, then split it between the taxes (the parts sum exactly).
        $sum = array_sum(array_map(static fn (array $tax): int => $tax['rate']->basisPoints, $rates));
        $parts = $sum === 0
            ? array_map(static fn (array $tax): Money => Money::zero($item->base->currency), $rates)
            : MoneyAllocator::allocate(
                $item->base->includedPart(Rate::bp($sum), $rounding),
                array_map(static fn (array $tax): int => $tax['rate']->basisPoints, $rates),
            );

        $amounts = [];

        foreach ($rates as $index => $tax) {
            $amounts[] = new TaxAmount($item->target, $item->lineId, $tax['code'], $tax['rate'], $parts[$index]);
        }

        return $amounts;
    }
}
