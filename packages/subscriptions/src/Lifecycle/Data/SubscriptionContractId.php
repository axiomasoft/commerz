<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Lifecycle\Data;

use Commerz\Identity\EntityId;
use Packman\Attributes\Api;

/** UUIDv7 identifier of a subscription contract. */
#[Api(since: '0.1')]
final readonly class SubscriptionContractId extends EntityId {}
