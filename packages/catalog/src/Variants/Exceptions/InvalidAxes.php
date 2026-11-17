<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Exceptions;

use Commerz\Catalog\Errors\Enums\CatalogErrorCode;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

/** The axes of a command do not match the product: an axis is missing or extra, or a value code is unknown. */
#[Api(since: '0.1')]
final class InvalidAxes extends CommerzException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Invalid axes: {$reason}.");
    }

    public function code(): string
    {
        return 'catalog.invalid_axes';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Validation;
    }

    public function context(): array
    {
        return ['reason' => $this->reason];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CatalogErrorCode::InvalidAxes,
            category: PackmanErrorCategory::Validation,
            retryable: false,
            details: $this->context(),
        );
    }
}
