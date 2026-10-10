<?php

declare(strict_types=1);

namespace Commerz\Sales\Refunds\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

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
}
