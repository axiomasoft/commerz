<?php

declare(strict_types=1);

namespace Commerz\Catalog\ProductTypes\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class ProductTypeNotFound extends NotFound
{
    /** @param  string  $reference  a product type id or code */
    public function __construct(string $reference)
    {
        parent::__construct('Product type', $reference, 'catalog.product_type_not_found');
    }
}
