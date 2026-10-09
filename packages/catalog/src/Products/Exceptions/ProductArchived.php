<?php

declare(strict_types=1);

namespace Commerz\Catalog\Products\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** The product is archived and takes no further changes. */
#[Api(since: '0.1')]
final class ProductArchived extends CommerzException
{
    public function __construct(public readonly string $product)
    {
        parent::__construct("Product '{$product}' is archived.");
    }

    public function code(): string
    {
        return 'catalog.product_archived';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['product' => $this->product];
    }
}
