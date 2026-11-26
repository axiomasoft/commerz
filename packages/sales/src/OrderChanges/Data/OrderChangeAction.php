<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Data;

use Commerz\Sales\OrderChanges\Enums\OrderChangeActionType;
use Packman\Attributes\Api;

/** A declarative mutation step recorded inside a draft OrderChange. */
#[Api(since: '0.1')]
interface OrderChangeAction
{
    public function type(): OrderChangeActionType;

    /** @return array<string, mixed> */
    public function toPayload(): array;
}
