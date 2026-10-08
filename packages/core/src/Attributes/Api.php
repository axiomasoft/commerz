<?php

declare(strict_types=1);

namespace Commerz\Attributes;

use Attribute;

/** Marks a type as part of the public, SemVer-covered API. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
#[Api(since: '0.1')]
final readonly class Api
{
    public function __construct(public string $since) {}
}
