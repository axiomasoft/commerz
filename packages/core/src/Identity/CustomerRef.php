<?php

declare(strict_types=1);

namespace Commerz\Identity;

use Commerz\Attributes\Api;

/** Reference to a customer owned by the host application. */
#[Api(since: '0.1')]
final readonly class CustomerRef extends Ref {}
