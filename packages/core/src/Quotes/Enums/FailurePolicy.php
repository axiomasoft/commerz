<?php

declare(strict_types=1);

namespace Commerz\Quotes\Enums;

use Commerz\Quotes\Contracts\QuotePipeline;
use Packman\Attributes\Api;

/**
 * Governs how {@see QuotePipeline} reacts when a stage or interceptor
 * throws an exception or exceeds its configured timeout budget.
 */
#[Api(since: '0.1')]
enum FailurePolicy: string
{
    case BlockOnFailure = 'block_on_failure';
    case IgnoreOnFailure = 'ignore_on_failure';
}
