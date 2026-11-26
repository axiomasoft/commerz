<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Data;

use Commerz\Identity\ActorRef;
use Packman\Attributes\Api;

/** Cancels a draft OrderChange without mutating the order. */
#[Api(since: '0.1')]
final readonly class CancelOrderChange
{
    public function __construct(
        public OrderChangeId $change,
        public ?ActorRef $actor = null,
    ) {}
}
