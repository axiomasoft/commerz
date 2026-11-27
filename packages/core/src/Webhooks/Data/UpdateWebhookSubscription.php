<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;
use SensitiveParameter;

#[Api(since: '0.1')]
final readonly class UpdateWebhookSubscription
{
    /** @var non-empty-list<string>|null */
    public ?array $eventTypes;

    /** @var array<string, string>|null */
    public ?array $headers;

    /**
     * @param  list<string>|null  $eventTypes
     * @param  array<string, string>|null  $headers
     */
    public function __construct(
        public WebhookSubscriptionId $subscription,
        public ?string $targetUrl = null,
        #[SensitiveParameter]
        public ?string $signingSecret = null,
        ?array $eventTypes = null,
        public ?int $maxAttempts = null,
        public ?int $failureThreshold = null,
        ?array $headers = null,
    ) {
        if ($targetUrl !== null) {
            CreateWebhookSubscription::assertTargetUrl($targetUrl);
        }

        if ($signingSecret !== null) {
            CreateWebhookSubscription::assertSigningSecret($signingSecret);
        }

        $this->eventTypes = $eventTypes === null ? null : CreateWebhookSubscription::normalizeEventTypes($eventTypes);

        if ($maxAttempts !== null && ($maxAttempts < 1 || $maxAttempts > 20)) {
            throw new InvalidCommand('maxAttempts', 'must be from 1 to 20', 'webhooks.invalid_subscription');
        }

        if ($failureThreshold !== null && ($failureThreshold < 1 || $failureThreshold > 1000)) {
            throw new InvalidCommand('failureThreshold', 'must be from 1 to 1000', 'webhooks.invalid_subscription');
        }

        $this->headers = $headers === null ? null : CreateWebhookSubscription::normalizeHeaders($headers);
    }
}
