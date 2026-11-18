<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Exceptions;

use Commerz\Catalog\Errors\Enums\CatalogErrorCode;
use Commerz\Errors\CommerzException;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** Required attributes of the product type are missing at creation, or a command tried to remove them. */
#[Api(since: '0.1')]
final class MissingRequiredAttributes extends CommerzException
{
    /** @param  list<string>  $codes */
    public function __construct(public readonly array $codes)
    {
        parent::__construct('Required attributes are missing: '.implode(', ', $codes).'.');
    }

    public function code(): string
    {
        return 'catalog.missing_required_attributes';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Validation;
    }

    public function context(): array
    {
        return ['codes' => implode(',', $this->codes)];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CatalogErrorCode::MissingRequiredAttributes,
            category: ErrorCategory::Validation,
            retryable: false,
            details: $this->context(),
        );
    }
}
