<?php

declare(strict_types=1);

namespace Commerz\Sales\Numbering\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** A generator produced a number that does not match `[A-Za-z0-9][A-Za-z0-9-]{0,63}`: a configuration defect, the order is not placed. */
#[Api(since: '0.1')]
final class InvalidOrderNumber extends CommerzException
{
    public function __construct(public readonly string $generator, public readonly string $number)
    {
        parent::__construct("Order number generator '{$generator}' returned an invalid number.");
    }

    public function code(): string
    {
        return 'sales.invalid_order_number';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Configuration;
    }

    public function context(): array
    {
        return ['generator' => $this->generator, 'number' => $this->number];
    }
}
