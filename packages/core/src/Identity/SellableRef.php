<?php

declare(strict_types=1);

namespace Commerz\Identity;

use Packman\Attributes\Api;

/** Reference to something that can be sold (product variant, service, bundle). */
#[Api(since: '0.1')]
final readonly class SellableRef extends Ref {}
