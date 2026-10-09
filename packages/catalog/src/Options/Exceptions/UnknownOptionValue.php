<?php

declare(strict_types=1);

namespace Commerz\Catalog\Options\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** A value code that does not belong to the option. */
#[Api(since: '0.1')]
final class UnknownOptionValue extends CommerzException
{
    public function __construct(public readonly string $option, public readonly string $value)
    {
        parent::__construct("Option '{$option}' has no value '{$value}'.");
    }

    public function code(): string
    {
        return 'catalog.unknown_option_value';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Validation;
    }

    public function context(): array
    {
        return ['option' => $this->option, 'value' => $this->value];
    }
}
