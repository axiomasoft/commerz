<?php

declare(strict_types=1);

namespace Commerz\Sales\Returns\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** More units were to go back on hand than the return holds for the line (REF-01). */
#[Api(since: '0.1')]
final class RestockExceedsReceived extends CommerzException
{
    public function __construct(public readonly string $lineId, public readonly int $restock, public readonly int $received)
    {
        parent::__construct("Line '{$lineId}' returns {$received} units, so {$restock} cannot be restocked.");
    }

    public function code(): string
    {
        return 'sales.restock_exceeds_received';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['line' => $this->lineId, 'restock' => $this->restock, 'received' => $this->received];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::RestockExceedsReceived,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
