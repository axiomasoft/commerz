<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Billing\Data;

use Commerz\Identity\EntityId;
use Packman\Attributes\Api;

/** UUIDv7 identifier of a subscription billing cycle. */
#[Api(since: '0.1')]
final readonly class BillingCycleId extends EntityId {}
