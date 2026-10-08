<?php

declare(strict_types=1);

namespace Commerz\Attributes;

use Attribute;

/** Marks an extension contract (service provider interface) with the way it is meant to be used. */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '0.1')]
final readonly class Spi
{
    public function __construct(public SpiKind $kind, public string $since = '0.1') {}
}
