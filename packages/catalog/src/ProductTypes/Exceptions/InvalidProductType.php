<?php

declare(strict_types=1);

namespace Commerz\Catalog\ProductTypes\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** The slots or axes of a product type are inconsistent. */
#[Api(since: '0.1')]
final class InvalidProductType extends CommerzException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Invalid product type: {$reason}.");
    }

    public function code(): string
    {
        return 'catalog.invalid_product_type';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Validation;
    }

    public function context(): array
    {
        return ['reason' => $this->reason];
    }
}
