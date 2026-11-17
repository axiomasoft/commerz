<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Enums;

use Packman\Attributes\Api;

/** A `draft` is a cart an operator assembles for a customer (CRM); it differs only in the payment term and the order origin. */
#[Api(since: '0.1')]
enum CartKind: string
{
    case Cart = 'cart';
    case Draft = 'draft';
}
