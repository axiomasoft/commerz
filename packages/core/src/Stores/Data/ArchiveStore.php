<?php

declare(strict_types=1);

namespace Commerz\Stores\Data;

use Packman\Attributes\Api;

/** Archives the store together with all its channels. Archiving twice is a no-op. */
#[Api(since: '0.1')]
final readonly class ArchiveStore
{
    public function __construct(public StoreId $store) {}
}
