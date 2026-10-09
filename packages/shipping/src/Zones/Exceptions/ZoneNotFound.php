<?php

declare(strict_types=1);

namespace Commerz\Shipping\Zones\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class ZoneNotFound extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('Shipping zone', $id, 'shipping.zone_not_found');
    }
}
