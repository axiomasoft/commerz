<?php

declare(strict_types=1);

namespace Commerz\Pricing\Taxes\Internal;

use Commerz\Ports\Customers\Contracts\CustomerDirectory;
use Commerz\Ports\Taxes\Contracts\TaxEngine;
use Commerz\Ports\Taxes\Data\CalculatedTax;
use Commerz\Ports\Taxes\Data\CalculatedTaxLine;
use Commerz\Ports\Taxes\Data\CalculateTax;
use Commerz\Ports\Taxes\Data\TaxExemption;
use Commerz\Pricing\Calculation\Contracts\TaxCalculator;
use Commerz\Pricing\Calculation\Data\TaxableItem;
use Commerz\Pricing\Calculation\Data\TaxAmount;
use Commerz\Pricing\Calculation\Data\TaxRequest;
use Commerz\Pricing\Calculation\Internal\TaxResultValidator;
use Commerz\Pricing\Configuration\PricingOptions;
use Commerz\Pricing\Taxes\Data\VatRegistration;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Illuminate\Contracts\Container\Container;
use Override;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Contributions\Contributions;

/**
 * Implements the core `TaxEngine` port on top of the active `tax_calculator` plugin contribution and `TaxResultValidator`.
 */
#[Internal]
final readonly class PricingTaxEngine implements TaxEngine
{
    public function __construct(private Container $container) {}

    #[Override]
    public function calculate(CalculateTax $request): CalculatedTax
    {
        $exemption = $request->exemption;
        $customer = $request->context->customer;

        if ($exemption === null && $customer !== null && $this->container->make(CustomerDirectory::class)->find($customer)?->taxExempt === true) {
            $exemption = TaxExemption::exempt('customer_tax_exempt');
        }

        $options = $this->container->make(PricingOptions::class);
        $items = [];
        $includedByKey = [];

        foreach ($request->lines as $line) {
            $taxClass = match ($line->target) {
                AdjustmentTarget::Line => $line->taxClass ?? $options->defaultTaxClass,
                AdjustmentTarget::Delivery => $line->taxClass ?? $options->deliveryTaxClass,
                AdjustmentTarget::Order => null,
            };

            if ($taxClass === null) {
                continue;
            }

            $item = new TaxableItem(
                target: $line->target,
                lineId: $line->lineId,
                sellable: $line->sellable,
                taxClass: $taxClass,
                quantity: $line->quantity,
                base: $line->base,
                included: $line->included,
            );
            $items[] = $item;
            $includedByKey[$item->key()] = $item->included;
        }

        if ($items === []) {
            return CalculatedTax::fromLines($request->context->currency, []);
        }

        $buyerVat = $request->buyerVatNumber === null ? null : new VatRegistration($request->buyerVatNumber);
        $taxRequest = new TaxRequest(
            context: $request->context,
            destination: $request->destination,
            items: $items,
            exemption: $exemption,
            buyerVat: $buyerVat,
            sellerCountry: $request->sellerCountry,
        );

        $calculator = $this->container->make(Contributions::class)->get(TaxCalculator::KIND, $options->taxCalculator, TaxCalculator::class);
        $result = $calculator->calculate($taxRequest);
        $this->container->make(TaxResultValidator::class)->assertValid($options->taxCalculator, $taxRequest, $result);

        $lines = array_map(
            static fn (TaxAmount $amount): CalculatedTaxLine => new CalculatedTaxLine(
                target: $amount->target,
                lineId: $amount->lineId,
                code: $amount->code,
                rate: $amount->rate,
                amount: $amount->amount,
                included: $includedByKey[$amount->itemKey()] ?? false,
            ),
            $result->amounts,
        );

        return CalculatedTax::fromLines(
            currency: $request->context->currency,
            lines: $lines,
            reverseCharge: $result->reverseCharge,
            exempt: $result->exempt,
            exemptionReason: $result->exemptionReason,
        );
    }
}
