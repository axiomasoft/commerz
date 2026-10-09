<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class VariantNotFound extends NotFound
{
    /** @param  string  $reference  a variant id or SKU */
    public function __construct(string $reference)
    {
        parent::__construct('Variant', $reference, 'catalog.variant_not_found');
    }
}
