<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;

/** Verified and deduplicated inbound webhook event delivered to handlers and domain listeners. */
#[Api(since: '0.1')]
final readonly class InboundWebhookPayload
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public InboundWebhookEventId $id,
        public string $source,
        public string $externalEventId,
        public string $eventType,
        public array $payload,
        public array $headers,
        public DateTimeImmutable $receivedAt,
    ) {}
}
