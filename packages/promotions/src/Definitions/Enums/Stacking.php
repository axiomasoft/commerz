<?php

declare(strict_types=1);

namespace Commerz\Promotions\Definitions\Enums;

use Commerz\Attributes\Api;

/** How a promotion combines with the others of its stage: `Exclusive` applies only as the first one and stops the rest. */
#[Api(since: '0.1')]
enum Stacking: string
{
    case Stackable = 'stackable';
    case Exclusive = 'exclusive';
}
