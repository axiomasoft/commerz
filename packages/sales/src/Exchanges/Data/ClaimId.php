<?php

declare(strict_types=1);

namespace Commerz\Sales\Exchanges\Data;

use Commerz\Identity\EntityId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ClaimId extends EntityId {}
