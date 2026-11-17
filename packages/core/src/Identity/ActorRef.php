<?php

declare(strict_types=1);

namespace Commerz\Identity;

use Packman\Attributes\Api;

/** Reference to who performs a command: an operator, a customer or the system. */
#[Api(since: '0.1')]
final readonly class ActorRef extends Ref {}
