<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** A filter names an attribute that is not filterable or is retired. */
#[Api(since: '0.1')]
final class AttributeNotFilterable extends CommerzException
{
    public function __construct(public readonly string $attribute)
    {
        parent::__construct("Attribute '{$attribute}' is not filterable.");
    }

    public function code(): string
    {
        return 'catalog.attribute_not_filterable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Validation;
    }

    public function context(): array
    {
        return ['attribute' => $this->attribute];
    }
}
