<?php

declare(strict_types=1);

namespace Commerz\B2b\BusinessUnits\Data;

use Commerz\B2b\BusinessUnits\Enums\AssociateRole;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Money;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class AssociateView
{
    public function __construct(
        public BusinessUnitId $businessUnitId,
        public BusinessUnitId $definedOnBusinessUnitId,
        public CustomerRef $customer,
        public AssociateRole $role,
        public ?Money $perOrderSpendingLimit,
        public ?Money $periodSpendingLimit,
        public bool $inherited,
        public DateTimeImmutable $assignedAt,
    ) {}

    public function exceedsPerOrderLimit(Money $amount): bool
    {
        if ($this->perOrderSpendingLimit === null) {
            return false;
        }

        return $amount->compare($this->perOrderSpendingLimit) > 0;
    }
}
