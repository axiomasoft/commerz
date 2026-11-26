<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Internal;

use Commerz\Outbox\Events\IntegrationEventDelivered;
use Commerz\Webhooks\Contracts\WebhookSubscriptions;
use Packman\Attributes\Internal;

/**
 * Bridges `DatabaseOutbox` / `OutboxRelay` (`IntegrationEventDelivered`) to outgoing `WebhookSubscriptions`.
 */
#[Internal]
final readonly class WebhookOutboxListener
{
    public function __construct(
        private WebhookSubscriptions $subscriptions,
    ) {}

    public function handle(IntegrationEventDelivered $delivered): void
    {
        $enqueued = $this->subscriptions->enqueue($delivered->event);

        if ($enqueued !== []) {
            $this->subscriptions->dispatchPending(count($enqueued));
        }
    }
}
