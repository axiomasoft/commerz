<?php

declare(strict_types=1);

namespace Commerz\Shipping\Methods\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class MethodNotFound extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('Shipping method', $id, 'shipping.method_not_found');
    }
}
