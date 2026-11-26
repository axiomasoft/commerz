<?php

declare(strict_types=1);

namespace Commerz\B2b\Approvals\Data;

use Commerz\B2b\BusinessUnits\Enums\AssociateRole;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ApprovalEvaluation
{
    /**
     * @param  list<string>  $reasons
     * @param  list<ApprovalRuleId>  $matchedRules
     */
    public function __construct(
        public bool $requiresApproval,
        public array $reasons,
        public AssociateRole $requiredRole,
        public int $requiredApprovals,
        public array $matchedRules,
    ) {}
}
