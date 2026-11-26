<?php

declare(strict_types=1);

namespace Commerz\StoredValue\Ledger\Data;

use Commerz\Identity\EntityId;
use Packman\Attributes\Api;

/** UUIDv7 identifier of a stored-value account (gift card or customer wallet). */
#[Api(since: '0.1')]
final readonly class AccountId extends EntityId {}
