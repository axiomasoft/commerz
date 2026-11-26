<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Events;

use Commerz\Webhooks\Data\WebhookSubscriptionView;
use Packman\Attributes\Api;

/** Dispatched after commit when consecutive endpoint failures trip the circuit-breaker and auto-disable a subscription. */
#[Api(since: '0.1')]
final readonly class WebhookSubscriptionDisabled
{
    public function __construct(public WebhookSubscriptionView $subscription) {}
}
