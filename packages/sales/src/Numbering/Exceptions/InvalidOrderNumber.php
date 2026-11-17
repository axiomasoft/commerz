<?php

declare(strict_types=1);

namespace Commerz\Sales\Numbering\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

/** A generator produced a number that is not 1..64 characters of `A-Za-z0-9-` with at least one letter or digit: a configuration defect, the order is not placed. */
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

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::InvalidOrderNumber,
            category: PackmanErrorCategory::Validation,
            retryable: false,
            details: $this->context(),
        );
    }
}
