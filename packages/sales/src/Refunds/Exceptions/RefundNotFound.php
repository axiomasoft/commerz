<?php

declare(strict_types=1);

namespace Commerz\Sales\Refunds\Exceptions;

use Commerz\Errors\NotFound;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class RefundNotFound extends NotFound
{
    /** @param  string  $id  the refund id, reference or the provider's id it was looked up by */
    public function __construct(string $id)
    {
        parent::__construct('Refund', $id, 'sales.refund_not_found');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::RefundNotFound,
            category: ErrorCategory::NotFound,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
