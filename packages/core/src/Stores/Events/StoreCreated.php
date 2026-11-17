<?php

declare(strict_types=1);

namespace Commerz\Stores\Events;

use Commerz\Stores\Data\StoreView;
use Packman\Attributes\Api;

/** Dispatched after the creating transaction commits. */
#[Api(since: '0.1')]
final readonly class StoreCreated
{
    public function __construct(public StoreView $store) {}
}
