<?php

declare(strict_types=1);

namespace Commerz\Ports\Stock\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\Enums\CommerzErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** A reservation would take more than is free; nothing of the command was reserved. */
#[Api(since: '0.1')]
final class InsufficientStock extends CommerzException
{
    public function __construct(
        public readonly string $sellable,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct("Insufficient stock of '{$sellable}': requested {$requested}, available {$available}.");
    }

    public function code(): string
    {
        return 'stock.insufficient_stock';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['sellable' => $this->sellable, 'requested' => $this->requested, 'available' => $this->available];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CommerzErrorCode::InsufficientStock,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
