<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Catalog\ProductTypes\Enums\AttributeLevel;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** The product type has no slot for the attribute on this level (product or variant). */
#[Api(since: '0.1')]
final class AttributeNotAllowed extends CommerzException
{
    public function __construct(public readonly string $attribute, public readonly AttributeLevel $level)
    {
        parent::__construct("Attribute '{$attribute}' is not allowed on the {$level->value} level of this product type.");
    }

    public function code(): string
    {
        return 'catalog.attribute_not_allowed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Validation;
    }

    public function context(): array
    {
        return ['attribute' => $this->attribute, 'level' => $this->level->value];
    }
}
