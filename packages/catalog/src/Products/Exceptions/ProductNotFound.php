<?php

declare(strict_types=1);

namespace Commerz\Catalog\Products\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class ProductNotFound extends NotFound
{
    /** @param  string  $reference  a product id or handle */
    public function __construct(string $reference)
    {
        parent::__construct('Product', $reference, 'catalog.product_not_found');
    }
}
