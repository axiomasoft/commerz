<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Renewals\Data;

use Commerz\Identity\EntityId;
use Packman\Attributes\Api;

/** UUIDv7 identifier of a subscription renewal order record. */
#[Api(since: '0.1')]
final readonly class RenewalOrderId extends EntityId {}
