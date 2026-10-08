<?php

declare(strict_types=1);

namespace Commerz\Outbox\Internal;

final readonly class RelayReport
{
    public function __construct(
        public int $claimed,
        public int $delivered,
        public int $failed,
    ) {}
}
