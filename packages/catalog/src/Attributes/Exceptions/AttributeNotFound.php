<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class AttributeNotFound extends NotFound
{
    /** @param  string  $reference  an attribute id or code */
    public function __construct(string $reference)
    {
        parent::__construct('Attribute', $reference, 'catalog.attribute_not_found');
    }
}
