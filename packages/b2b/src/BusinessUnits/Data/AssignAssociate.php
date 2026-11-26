<?php

declare(strict_types=1);

namespace Commerz\B2b\BusinessUnits\Data;

use Commerz\B2b\BusinessUnits\Enums\AssociateRole;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Money;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class AssignAssociate
{
    public function __construct(
        public BusinessUnitId $businessUnitId,
        public CustomerRef $customer,
        public AssociateRole $role,
        public ?Money $perOrderSpendingLimit = null,
        public ?Money $periodSpendingLimit = null,
    ) {
        if ($perOrderSpendingLimit !== null && $perOrderSpendingLimit->isNegative()) {
            throw new InvalidCommand('perOrderSpendingLimit', 'must not be negative', 'b2b.invalid_spending_limit');
        }

        if ($periodSpendingLimit !== null && $periodSpendingLimit->isNegative()) {
            throw new InvalidCommand('periodSpendingLimit', 'must not be negative', 'b2b.invalid_spending_limit');
        }

        if ($perOrderSpendingLimit !== null && $periodSpendingLimit !== null && ! $perOrderSpendingLimit->currency->equals($periodSpendingLimit->currency)) {
            throw new InvalidCommand('periodSpendingLimit', 'currency must match perOrderSpendingLimit', 'b2b.currency_mismatch');
        }
    }
}
