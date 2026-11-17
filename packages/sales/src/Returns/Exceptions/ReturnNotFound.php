<?php

declare(strict_types=1);

namespace Commerz\Sales\Returns\Exceptions;

use Commerz\Errors\NotFound;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

#[Api(since: '0.1')]
final class ReturnNotFound extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('Return', $id, 'sales.return_not_found');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::ReturnNotFound,
            category: PackmanErrorCategory::NotFound,
            retryable: false,
            details: $this->context(),
        );
    }
}
