<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Data;

use Commerz\Identity\ActorRef;
use Packman\Attributes\Api;

/** Appends a mutation action to a draft OrderChange. */
#[Api(since: '0.1')]
final readonly class AddOrderChangeAction
{
    public function __construct(
        public OrderChangeId $change,
        public OrderChangeAction $action,
        public ?ActorRef $actor = null,
    ) {}
}
