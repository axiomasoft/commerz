<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/**
 * The line has fewer units left for the operation than asked (ORD-03): units neither cancelled nor fulfilled for a
 * cancellation or a fulfillment, fulfilled units not yet returned for a return.
 */
#[Api(since: '0.1')]
final class QuantityExceedsOutstanding extends CommerzException
{
    public function __construct(public readonly string $lineId, public readonly int $requested, public readonly int $available)
    {
        parent::__construct("Line '{$lineId}' has {$available} units left, not the requested {$requested}.");
    }

    public function code(): string
    {
        return 'sales.quantity_exceeds_outstanding';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['line' => $this->lineId, 'requested' => $this->requested, 'available' => $this->available];
    }
}
