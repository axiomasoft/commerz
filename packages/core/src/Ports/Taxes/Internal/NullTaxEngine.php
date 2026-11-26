<?php

declare(strict_types=1);

namespace Commerz\Ports\Taxes\Internal;

use Commerz\Ports\Taxes\Contracts\TaxEngine;
use Commerz\Ports\Taxes\Data\CalculatedTax;
use Commerz\Ports\Taxes\Data\CalculateTax;
use Commerz\Ports\Taxes\Enums\TaxExemptionType;
use Override;
use Packman\Attributes\Internal;

/** No-op default when `axiomasoft/commerz-pricing` is not installed: collects zero tax. */
#[Internal]
final readonly class NullTaxEngine implements TaxEngine
{
    #[Override]
    public function calculate(CalculateTax $request): CalculatedTax
    {
        $reverseCharge = $request->exemption?->type === TaxExemptionType::ReverseCharge;
        $exempt = $request->exemption?->type === TaxExemptionType::Exempt;

        return CalculatedTax::fromLines(
            currency: $request->context->currency,
            lines: [],
            reverseCharge: $reverseCharge,
            exempt: $exempt,
            exemptionReason: $request->exemption?->reason,
        );
    }
}
