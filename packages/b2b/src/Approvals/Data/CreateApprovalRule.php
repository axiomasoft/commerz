<?php

declare(strict_types=1);

namespace Commerz\B2b\Approvals\Data;

use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\BusinessUnits\Enums\AssociateRole;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class CreateApprovalRule
{
    public string $name;

    public function __construct(
        public BusinessUnitId $businessUnitId,
        string $name,
        public Money $thresholdAmount,
        public AssociateRole $approverRole = AssociateRole::Approver,
        public int $requiredApprovals = 1,
        public bool $appliesToDescendants = true,
    ) {
        $trimmedName = trim($name);

        if ($trimmedName === '' || strlen($trimmedName) > 191) {
            throw new InvalidCommand('name', 'must be 1..191 bytes', 'b2b.invalid_approval_rule');
        }

        if ($thresholdAmount->isNegative()) {
            throw new InvalidCommand('thresholdAmount', 'must not be negative', 'b2b.invalid_approval_rule');
        }

        if (! $approverRole->canApprove()) {
            throw new InvalidCommand('approverRole', "role '{$approverRole->value}' cannot approve flows", 'b2b.invalid_approval_rule');
        }

        if ($requiredApprovals < 1 || $requiredApprovals > 20) {
            throw new InvalidCommand('requiredApprovals', 'must be between 1 and 20', 'b2b.invalid_approval_rule');
        }

        $this->name = $trimmedName;
    }
}
