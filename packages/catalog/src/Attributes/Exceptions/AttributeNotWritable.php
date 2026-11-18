<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Exceptions;

use Commerz\Catalog\Errors\Enums\CatalogErrorCode;
use Commerz\Errors\CommerzException;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** The attribute is deprecated or retired: existing values stay, new ones are refused. */
#[Api(since: '0.1')]
final class AttributeNotWritable extends CommerzException
{
    public function __construct(public readonly string $attribute, public readonly string $status)
    {
        parent::__construct("Attribute '{$attribute}' is {$status} and takes no new values.");
    }

    public function code(): string
    {
        return 'catalog.attribute_not_writable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['attribute' => $this->attribute, 'status' => $this->status];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CatalogErrorCode::AttributeNotWritable,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
