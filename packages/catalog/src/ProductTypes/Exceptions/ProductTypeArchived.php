<?php

declare(strict_types=1);

namespace Commerz\Catalog\ProductTypes\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** The product type is archived and takes no new products or slots. */
#[Api(since: '0.1')]
final class ProductTypeArchived extends CommerzException
{
    public function __construct(public readonly string $type)
    {
        parent::__construct("Product type '{$type}' is archived.");
    }

    public function code(): string
    {
        return 'catalog.product_type_archived';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['type' => $this->type];
    }
}
