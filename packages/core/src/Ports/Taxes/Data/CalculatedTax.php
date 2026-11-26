<?php

declare(strict_types=1);

namespace Commerz\Ports\Taxes\Data;

use Commerz\Money\Currency;
use Commerz\Money\Money;
use Packman\Attributes\Api;

/** Read-only tax evaluation result from `TaxEngine`. */
#[Api(since: '0.1')]
final readonly class CalculatedTax
{
    /**
     * @param  list<CalculatedTaxLine>  $lines
     */
    public function __construct(
        public array $lines,
        public Money $total,
        public Money $excludedTotal,
        public Money $includedTotal,
        public bool $reverseCharge = false,
        public bool $exempt = false,
        public ?string $exemptionReason = null,
    ) {}

    /**
     * @param  list<CalculatedTaxLine>  $lines
     */
    public static function fromLines(
        Currency $currency,
        array $lines,
        bool $reverseCharge = false,
        bool $exempt = false,
        ?string $exemptionReason = null,
    ): self {
        $total = Money::zero($currency);
        $excluded = Money::zero($currency);
        $included = Money::zero($currency);

        foreach ($lines as $line) {
            $total = $total->plus($line->amount);

            if ($line->included) {
                $included = $included->plus($line->amount);
            } else {
                $excluded = $excluded->plus($line->amount);
            }
        }

        return new self($lines, $total, $excluded, $included, $reverseCharge, $exempt, $exemptionReason);
    }
}
