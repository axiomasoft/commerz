<?php

declare(strict_types=1);

namespace Commerz\Shipping\Rates\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** The parameters of a rate are wrong; `field` is the parameter, e.g. `amount` or `steps.1.up_to_grams`. */
#[Api(since: '0.1')]
final class InvalidRateConfig extends CommerzException
{
    public function __construct(public readonly string $field, public readonly string $reason)
    {
        parent::__construct("Invalid rate parameter {$field}: {$reason}.");
    }

    public function code(): string
    {
        return 'shipping.invalid_rate_config';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Validation;
    }

    public function context(): array
    {
        return ['field' => $this->field, 'reason' => $this->reason];
    }
}
