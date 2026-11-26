<?php

declare(strict_types=1);

namespace Commerz\Ports\Assets\Data;

use Commerz\Identity\Ref;
use Packman\Attributes\Api;

/** Opaque reference (`type:id`) to a media or file asset owned by the host application. */
#[Api(since: '0.1')]
final readonly class AssetRef extends Ref {}
