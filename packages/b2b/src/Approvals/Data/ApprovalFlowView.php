<?php

declare(strict_types=1);

namespace Commerz\B2b\Approvals\Data;

use Commerz\B2b\Approvals\Enums\ApprovalStatus;
use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\BusinessUnits\Enums\AssociateRole;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Money;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ApprovalFlowView
{
    /**
     * @param  list<string>  $reasons
     * @param  list<ApprovalDecisionView>  $decisions
     */
    public function __construct(
        public ApprovalFlowId $id,
        public BusinessUnitId $businessUnitId,
        public CustomerRef $requestedBy,
        public string $subjectRef,
        public Money $amount,
        public ApprovalStatus $status,
        public AssociateRole $requiredRole,
        public int $requiredApprovals,
        public array $reasons,
        public array $decisions,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $resolvedAt,
    ) {}
}
