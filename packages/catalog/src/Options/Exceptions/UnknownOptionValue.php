<?php

declare(strict_types=1);

namespace Commerz\Catalog\Options\Exceptions;

use Commerz\Catalog\Errors\Enums\CatalogErrorCode;
use Commerz\Errors\CommerzException;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

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

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CatalogErrorCode::UnknownOptionValue,
            category: ErrorCategory::Validation,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
