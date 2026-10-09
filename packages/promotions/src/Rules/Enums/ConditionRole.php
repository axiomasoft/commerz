<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Enums;

use Commerz\Attributes\Api;

#[Api(since: '0.1')]
enum ConditionRole: string
{
    /** Narrows the lines the effects act on. */
    case LineFilter = 'line_filter';

    /** Says yes or no for the whole quote. */
    case Qualifier = 'qualifier';
}
