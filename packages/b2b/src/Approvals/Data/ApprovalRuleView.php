<?php

declare(strict_types=1);

namespace Commerz\B2b\Approvals\Data;

use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\BusinessUnits\Enums\AssociateRole;
use Commerz\Money\Money;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ApprovalRuleView
{
    public function __construct(
        public ApprovalRuleId $id,
        public BusinessUnitId $businessUnitId,
        public string $name,
        public Money $thresholdAmount,
        public AssociateRole $approverRole,
        public int $requiredApprovals,
        public bool $appliesToDescendants,
        public bool $active,
        public DateTimeImmutable $createdAt,
    ) {}
}
