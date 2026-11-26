<?php

declare(strict_types=1);

namespace Commerz\B2b\Approvals\Data;

use Commerz\B2b\Approvals\Enums\ApprovalStatus;
use Commerz\B2b\BusinessUnits\Enums\AssociateRole;
use Commerz\Identity\CustomerRef;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ApprovalDecisionView
{
    public function __construct(
        public CustomerRef $approver,
        public AssociateRole $role,
        public ApprovalStatus $decision,
        public ?string $comment,
        public DateTimeImmutable $decidedAt,
    ) {}
}
