<?php

declare(strict_types=1);

namespace Commerz\B2b\BusinessUnits\Exceptions;

use Commerz\B2b\Errors\B2bDomainException;
use Commerz\B2b\Errors\Enums\B2bErrorCode;
use Packman\Attributes\Api;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class HierarchyDepthExceeded extends B2bDomainException
{
    public function __construct(
        public readonly int $maxDepth,
        public readonly int $attemptedDepth,
    ) {
        parent::__construct(
            b2bCode: B2bErrorCode::HierarchyDepthExceeded,
            errorCategory: ErrorCategory::Validation,
            message: "Business unit depth {$attemptedDepth} exceeds maximum configured hierarchy depth {$maxDepth}.",
            contextDetails: [
                'max_depth' => $maxDepth,
                'attempted_depth' => $attemptedDepth,
            ],
            pointerPath: '/parent_id',
        );
    }
}
