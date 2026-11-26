<?php

declare(strict_types=1);

namespace Commerz\Identity;

use Packman\Attributes\Api;

/** Reference to a seller owned by the host application or a marketplace module. */
#[Api(since: '0.1')]
final readonly class SellerRef extends Ref {}
