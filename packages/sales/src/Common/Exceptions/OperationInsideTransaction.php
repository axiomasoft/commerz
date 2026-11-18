<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/**
 * The operation calls a payment gateway or a carrier and must run outside any database transaction (PAY-02): a
 * rollback after the network call would forget a payment or a booking the provider already made.
 */
#[Api(since: '0.1')]
final class OperationInsideTransaction extends CommerzException
{
    /** @param  string  $operation  e.g. `sales.payments.initiate` */
    public function __construct(public readonly string $operation)
    {
        parent::__construct("'{$operation}' calls an external service and cannot run inside a database transaction.");
    }

    public function code(): string
    {
        return 'sales.operation_inside_transaction';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Validation;
    }

    public function context(): array
    {
        return ['operation' => $this->operation];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::OperationInsideTransaction,
            category: ErrorCategory::Validation,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
