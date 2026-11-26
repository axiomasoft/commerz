<?php

declare(strict_types=1);

namespace Commerz\Time\Contracts;

use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Source of the current UTC instant across all Commerz packages.
 */
#[Api(since: '0.1')]
interface Clock
{
    public function now(): DateTimeImmutable;
}
