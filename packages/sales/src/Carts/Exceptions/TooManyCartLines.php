<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Exceptions;

use Commerz\Errors\InvalidCommand;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** The cart would hold more lines than `commerz-sales.carts.max_lines`. */
#[Api(since: '0.1')]
final class TooManyCartLines extends InvalidCommand
{
    public function __construct(public readonly int $max)
    {
        parent::__construct('lines', "a cart holds at most {$max} lines", 'sales.too_many_cart_lines');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::TooManyCartLines,
            category: ErrorCategory::Validation,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
