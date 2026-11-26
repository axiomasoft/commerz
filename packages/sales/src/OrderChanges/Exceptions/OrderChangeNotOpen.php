<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** The order change is no longer in `draft` status and cannot be mutated or confirmed. */
#[Api(since: '0.1')]
final class OrderChangeNotOpen extends CommerzException
{
    public function __construct(public readonly string $change, public readonly string $status)
    {
        parent::__construct("Order change '{$change}' is '{$status}', not open.");
    }

    public function code(): string
    {
        return SalesErrorCode::OrderChangeNotOpen->value;
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['change' => $this->change, 'status' => $this->status];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::OrderChangeNotOpen,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
