<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Data;

use Commerz\Identity\EntityId;
use Packman\Attributes\Api;

/** Equals the id of the cart line the order line was placed from. */
#[Api(since: '0.1')]
final readonly class OrderLineId extends EntityId {}
