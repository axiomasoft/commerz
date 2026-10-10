<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Enums;

use Commerz\Attributes\Api;

/** The stage of one fulfillment document: `created` (stock taken), `shipped` (left with a carrier), `delivered`. */
#[Api(since: '0.1')]
enum FulfillmentStatus: string
{
    case Created = 'created';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
}
