<?php

declare(strict_types=1);

namespace Commerz\Plugins;

use Commerz\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ContributionDescription
{
    /** @param  class-string  $class */
    public function __construct(public string $kind, public string $key, public string $class) {}
}
