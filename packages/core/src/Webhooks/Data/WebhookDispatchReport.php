<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Data;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class WebhookDispatchReport
{
    public function __construct(
        public int $attempted,
        public int $delivered,
        public int $retried,
        public int $failed,
        public int $autoDisabled,
    ) {}
}
