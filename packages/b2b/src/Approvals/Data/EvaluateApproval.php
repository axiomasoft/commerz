<?php

declare(strict_types=1);

namespace Commerz\B2b\Approvals\Data;

use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Money;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class EvaluateApproval
{
    public function __construct(
        public BusinessUnitId $businessUnitId,
        public CustomerRef $buyer,
        public Money $amount,
    ) {}
}
