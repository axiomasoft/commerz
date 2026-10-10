<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Data;

use Commerz\Attributes\Api;
use Commerz\Identity\EntityId;

/** Equals the id of the cart line the order line was placed from. */
#[Api(since: '0.1')]
final readonly class OrderLineId extends EntityId {}
