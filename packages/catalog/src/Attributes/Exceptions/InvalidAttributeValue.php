<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Exceptions;

use Commerz\Catalog\Errors\Enums\CatalogErrorCode;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

/** A value does not fit the attribute's type or config. */
#[Api(since: '0.1')]
final class InvalidAttributeValue extends CommerzException
{
    public function __construct(public readonly string $attribute, public readonly string $reason)
    {
        parent::__construct("Invalid value for attribute '{$attribute}': {$reason}.");
    }

    public function code(): string
    {
        return 'catalog.invalid_attribute_value';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Validation;
    }

    public function context(): array
    {
        return ['attribute' => $this->attribute, 'reason' => $this->reason];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CatalogErrorCode::InvalidAttributeValue,
            category: PackmanErrorCategory::Validation,
            retryable: false,
            details: $this->context(),
        );
    }
}
