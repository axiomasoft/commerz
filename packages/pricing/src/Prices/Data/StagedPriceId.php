<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Data;

use Commerz\Identity\EntityId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class StagedPriceId extends EntityId {}
