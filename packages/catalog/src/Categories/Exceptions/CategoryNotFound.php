<?php

declare(strict_types=1);

namespace Commerz\Catalog\Categories\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class CategoryNotFound extends NotFound
{
    /** @param  string  $reference  a category id or code */
    public function __construct(string $reference)
    {
        parent::__construct('Category', $reference, 'catalog.category_not_found');
    }
}
