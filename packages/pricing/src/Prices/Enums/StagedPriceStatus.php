<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum StagedPriceStatus: string
{
    case Staged = 'staged';
    case Published = 'published';
    case Discarded = 'discarded';
}
