<?php

declare(strict_types=1);

namespace Commerz\Sales\Refunds\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

/** Another process is asking the gateway for this refund under a live lease; try again after it. */
#[Api(since: '0.1')]
final class RefundInProgress extends CommerzException
{
    public function __construct(public readonly string $refund)
    {
        parent::__construct("Refund '{$refund}' is being issued by another process.");
    }

    public function code(): string
    {
        return 'sales.refund_in_progress';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['refund' => $this->refund];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::RefundInProgress,
            category: PackmanErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}
