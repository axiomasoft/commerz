<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

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
        return ErrorCategory::Configuration;
    }

    public function context(): array
    {
        return ['operation' => $this->operation];
    }
}
