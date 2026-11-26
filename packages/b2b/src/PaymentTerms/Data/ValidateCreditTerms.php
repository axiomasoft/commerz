<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Data;

use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\Money\Money;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ValidateCreditTerms
{
    /** @var list<SellingPlanSummary> */
    public array $sellingPlans;

    /** @param list<object> $sellingPlans */
    public function __construct(
        public BusinessUnitId $businessUnitId,
        public PaymentTerms $paymentTerms,
        public Money $orderTotal,
        array $sellingPlans = [],
        public ?DateTimeImmutable $issuedAt = null,
    ) {
        $this->sellingPlans = array_map(SellingPlanSummary::from(...), $sellingPlans);
    }
}
