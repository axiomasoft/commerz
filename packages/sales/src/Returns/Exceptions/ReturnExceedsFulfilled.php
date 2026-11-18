<?php

declare(strict_types=1);

namespace Commerz\Sales\Returns\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** The line has fewer fulfilled units left to return (fulfilled − returned − in open returns) than asked; none for a cancelled order. */
#[Api(since: '0.1')]
final class ReturnExceedsFulfilled extends CommerzException
{
    public function __construct(public readonly string $lineId, public readonly int $requested, public readonly int $available)
    {
        parent::__construct("Line '{$lineId}' has {$available} units to return, not the requested {$requested}.");
    }

    public function code(): string
    {
        return 'sales.return_exceeds_fulfilled';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['line' => $this->lineId, 'requested' => $this->requested, 'available' => $this->available];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::ReturnExceedsFulfilled,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}
