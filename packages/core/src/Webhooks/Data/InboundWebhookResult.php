<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Data;

use Commerz\Webhooks\Enums\InboundWebhookOutcome;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class InboundWebhookResult
{
    public function __construct(
        public InboundWebhookEventId $id,
        public string $source,
        public string $externalEventId,
        public string $eventType,
        public bool $duplicate,
        public InboundWebhookOutcome $outcome,
    ) {}
}
