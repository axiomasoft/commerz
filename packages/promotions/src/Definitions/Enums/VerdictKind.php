<?php

declare(strict_types=1);

namespace Commerz\Promotions\Definitions\Enums;

use Commerz\Attributes\Api;

/** What happened to a promotion in a quote: applied, refused by a check, or passed over because another one stopped it. */
#[Api(since: '0.1')]
enum VerdictKind: string
{
    case Applied = 'applied';
    case Rejected = 'rejected';
    case Skipped = 'skipped';
}
