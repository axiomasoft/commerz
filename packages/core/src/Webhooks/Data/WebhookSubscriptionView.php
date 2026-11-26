<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Data;

use Commerz\Webhooks\Enums\WebhookSubscriptionStatus;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** Read-only view of a webhook subscription; signing secrets are intentionally omitted. */
#[Api(since: '0.1')]
final readonly class WebhookSubscriptionView
{
    /**
     * @param  list<string>  $eventTypes
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public WebhookSubscriptionId $id,
        public string $code,
        public string $targetUrl,
        public array $eventTypes,
        public WebhookSubscriptionStatus $status,
        public int $maxAttempts,
        public int $failureThreshold,
        public int $consecutiveFailures,
        public ?DateTimeImmutable $disabledAt,
        public ?string $disabledReason,
        public array $headers,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    public function matchesEventType(string $eventType): bool
    {
        foreach ($this->eventTypes as $pattern) {
            if ($pattern === '*' || $pattern === $eventType) {
                return true;
            }

            if (str_ends_with($pattern, '.*')) {
                $prefix = substr($pattern, 0, -1);

                if (str_starts_with($eventType, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
