<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class OrderChangeNotFound extends CommerzException
{
    public function __construct(public readonly string $id)
    {
        parent::__construct("Order change '{$id}' was not found.");
    }

    public function code(): string
    {
        return SalesErrorCode::OrderChangeNotFound->value;
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }

    public function context(): array
    {
        return ['entity' => 'order_change', 'id' => $this->id];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::OrderChangeNotFound,
            category: ErrorCategory::NotFound,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
