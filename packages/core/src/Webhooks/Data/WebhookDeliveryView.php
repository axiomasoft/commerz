<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Data;

use Commerz\Webhooks\Enums\WebhookDeliveryStatus;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class WebhookDeliveryView
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public WebhookDeliveryId $id,
        public WebhookSubscriptionId $subscriptionId,
        public string $eventId,
        public string $eventType,
        public int $eventVersion,
        public string $aggregateRef,
        public array $payload,
        public DateTimeImmutable $occurredAt,
        public WebhookDeliveryStatus $status,
        public int $attempts,
        public DateTimeImmutable $availableAt,
        public ?DateTimeImmutable $deliveredAt,
        public ?int $lastHttpStatus,
        public ?string $lastError,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
