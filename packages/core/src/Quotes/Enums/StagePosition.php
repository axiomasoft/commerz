<?php

declare(strict_types=1);

namespace Commerz\Quotes\Enums;

use Commerz\Attributes\Api;

/** Where a stage runs relative to its anchor. Only the anchor's owner takes `At`, and only one stage per anchor. */
#[Api(since: '0.1')]
enum StagePosition: string
{
    case Before = 'before';
    case At = 'at';
    case After = 'after';

    /** @return int<0, 2> */
    public function order(): int
    {
        return match ($this) {
            self::Before => 0,
            self::At => 1,
            self::After => 2,
        };
    }
}
