<?php

declare(strict_types=1);

namespace Commerz\Sales\Returns\Data;

use Commerz\Identity\ActorRef;
use Packman\Attributes\Api;

/** Withdraws a requested return; its units become available to return again. */
#[Api(since: '0.1')]
final readonly class CancelReturn
{
    public function __construct(public ReturnId $return, public ?ActorRef $actor = null) {}
}
