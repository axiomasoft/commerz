<?php

declare(strict_types=1);

namespace Commerz\B2b\Approvals\Exceptions;

use Commerz\B2b\Errors\B2bDomainException;
use Commerz\B2b\Errors\Enums\B2bErrorCode;
use Packman\Attributes\Api;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class ApprovalFlowNotFound extends B2bDomainException
{
    public function __construct(public readonly string $identifier)
    {
        parent::__construct(
            b2bCode: B2bErrorCode::ApprovalFlowNotFound,
            errorCategory: ErrorCategory::NotFound,
            message: "Approval flow '{$identifier}' was not found.",
            contextDetails: ['entity' => 'approval_flow', 'id' => $identifier],
        );
    }
}
