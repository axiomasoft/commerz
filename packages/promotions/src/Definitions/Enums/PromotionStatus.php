<?php

declare(strict_types=1);

namespace Commerz\Promotions\Definitions\Enums;

use Commerz\Attributes\Api;

/** `Archived` is terminal. */
#[Api(since: '0.1')]
enum PromotionStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';
}
