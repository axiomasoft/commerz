<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** An attribute definition config (or a combination of config, choices and filterable) is not valid for its type. */
#[Api(since: '0.1')]
final class InvalidAttributeConfig extends CommerzException
{
    public function __construct(public readonly string $attribute, public readonly string $reason)
    {
        parent::__construct("Invalid config '{$attribute}': {$reason}.");
    }

    public function code(): string
    {
        return 'catalog.invalid_attribute_config';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Validation;
    }

    public function context(): array
    {
        return ['attribute' => $this->attribute, 'reason' => $this->reason];
    }
}
