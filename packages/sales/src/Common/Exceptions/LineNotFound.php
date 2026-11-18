<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Exceptions;

use Commerz\Errors\NotFound;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** The order has no line with this id. */
#[Api(since: '0.1')]
final class LineNotFound extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('Order line', $id, 'sales.line_not_found');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::LineNotFound,
            category: ErrorCategory::NotFound,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
