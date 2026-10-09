<?php

declare(strict_types=1);

namespace Commerz\Promotions\Definitions\Data;

use Commerz\Attributes\Api;
use Commerz\Identity\EntityId;

#[Api(since: '0.1')]
final readonly class PromotionId extends EntityId {}
