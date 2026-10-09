<?php

declare(strict_types=1);

namespace Commerz\Pricing\Taxes\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class TaxClassNotFound extends NotFound
{
    public function __construct(string $code)
    {
        parent::__construct('Tax class', $code, 'pricing.tax_class_not_found');
    }
}
