<?php

declare(strict_types=1);

namespace Commerz\Testkit\Tests\Unit;

use Commerz\Money\Money;
use Commerz\Money\Rate;
use Commerz\Pricing\Calculation\Contracts\TaxCalculator;
use Commerz\Pricing\Calculation\Data\TaxableItem;
use Commerz\Pricing\Calculation\Data\TaxAmount;
use Commerz\Pricing\Calculation\Data\TaxRequest;
use Commerz\Pricing\Calculation\Data\TaxResult;
use Commerz\Pricing\Taxes\Exceptions\TaxRateNotFound;
use Commerz\Testkit\Contracts\AssertsTaxCalculatorCompliance;
use PHPUnit\Framework\TestCase;

final class TaxCalculatorComplianceTest extends TestCase
{
    use AssertsTaxCalculatorCompliance;

    private ?TaxCalculator $calculator = null;

    protected function taxCalculator(): TaxCalculator
    {
        return $this->calculator ??= new class implements TaxCalculator
        {
            public function key(): string
            {
                return 'test.tax';
            }

            public function calculate(TaxRequest $request): TaxResult
            {
                if ($request->isTaxExempt()) {
                    return new TaxResult(amounts: [], exempt: true, exemptionReason: $request->exemption->reason ?? 'exempt');
                }

                if ($request->isReverseCharge()) {
                    $zeroAmounts = array_map(
                        static fn ($item): TaxAmount => new TaxAmount(
                            $item->target,
                            $item->lineId,
                            'reverse_charge',
                            Rate::bp(0),
                            Money::zero($request->context->currency),
                        ),
                        $request->items,
                    );

                    return new TaxResult(amounts: $zeroAmounts, reverseCharge: true, exemptionReason: 'b2b_reverse_charge');
                }

                $rate = Rate::bp(825);
                $amounts = [];

                foreach ($request->items as $item) {
                    if ($item->taxClass === 'unknown_class') {
                        throw TaxRateNotFound::forClass($item->taxClass, 'US', $request->context->at->format(DATE_ATOM));
                    }

                    $amounts[] = new TaxAmount(
                        target: $item->target,
                        lineId: $item->lineId,
                        code: 'sales_tax',
                        rate: $rate,
                        amount: Money::ofMinor(
                            intdiv($item->base->amountMinor * $rate->basisPoints + 5000, 10000),
                            $item->base->currency,
                        ),
                    );
                }

                return new TaxResult($amounts);
            }
        };
    }

    /** @return list<TaxRequest> */
    protected function unresolvableTaxRequests(): array
    {
        $context = $this->taxContext();
        $base = $this->taxableRequest($context);

        return [
            new TaxRequest(
                context: $context,
                destination: $base->destination,
                items: [
                    new TaxableItem(
                        target: $base->items[0]->target,
                        lineId: $base->items[0]->lineId,
                        sellable: $base->items[0]->sellable,
                        taxClass: 'unknown_class',
                        quantity: 1,
                        base: $base->items[0]->base,
                        included: false,
                    ),
                ],
            ),
        ];
    }
}
