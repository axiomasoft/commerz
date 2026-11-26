<?php

declare(strict_types=1);

namespace Commerz\B2b\Approvals\Exceptions;

use Commerz\B2b\Approvals\Data\ApprovalFlowId;
use Commerz\B2b\Approvals\Enums\ApprovalStatus;
use Commerz\B2b\Errors\B2bDomainException;
use Commerz\B2b\Errors\Enums\B2bErrorCode;
use Packman\Attributes\Api;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class ApprovalFlowClosed extends B2bDomainException
{
    public function __construct(
        public readonly ApprovalFlowId $approvalFlowId,
        public readonly ApprovalStatus $status,
    ) {
        parent::__construct(
            b2bCode: B2bErrorCode::ApprovalFlowClosed,
            errorCategory: ErrorCategory::Conflict,
            message: "Approval flow '{$approvalFlowId->value}' is already {$status->value}.",
            contextDetails: [
                'approval_flow_id' => $approvalFlowId->value,
                'status' => $status->value,
            ],
        );
    }
}
