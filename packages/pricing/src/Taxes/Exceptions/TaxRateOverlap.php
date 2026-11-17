<?php

declare(strict_types=1);

namespace Commerz\Pricing\Taxes\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Commerz\Pricing\Errors\Enums\PricingErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

/** The window overlaps another window of the same rate (tax class, zone, tax code) (TAX-01). */
#[Api(since: '0.1')]
final class TaxRateOverlap extends CommerzException
{
    public function __construct(
        public readonly string $taxClass,
        public readonly string $zone,
        public readonly string $taxCode,
        public readonly ?string $conflictingRate,
    ) {
        parent::__construct("A rate '{$taxCode}' of tax class '{$taxClass}' in tax zone '{$zone}' already covers part of this window.");
    }

    public function code(): string
    {
        return 'pricing.tax_rate_overlap';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return [
            'tax_class' => $this->taxClass,
            'zone' => $this->zone,
            'tax_code' => $this->taxCode,
            'conflicting_rate' => $this->conflictingRate,
        ];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: PricingErrorCode::TaxRateOverlap,
            category: PackmanErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}
