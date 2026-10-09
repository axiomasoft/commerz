<?php

declare(strict_types=1);

namespace Commerz\Catalog\Options\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class OptionNotFound extends NotFound
{
    /** @param  string  $reference  an option id or code */
    public function __construct(string $reference)
    {
        parent::__construct('Option', $reference, 'catalog.option_not_found');
    }
}
