<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class FulfillmentNotFound extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('Fulfillment', $id, 'sales.fulfillment_not_found');
    }
}
