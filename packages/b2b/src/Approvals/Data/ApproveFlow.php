<?php

declare(strict_types=1);

namespace Commerz\B2b\Approvals\Data;

use Commerz\Identity\CustomerRef;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ApproveFlow
{
    public function __construct(
        public ApprovalFlowId $approvalFlowId,
        public CustomerRef $approver,
        public ?string $comment = null,
    ) {}
}
