<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Data;

use Packman\Attributes\Api;

/** Command to manually replay a webhook delivery, optionally re-enabling a circuit-broken or paused subscription. */
#[Api(since: '0.1')]
final readonly class ReplayWebhookDelivery
{
    public function __construct(
        public WebhookDeliveryId $delivery,
        public bool $reactivateSubscription = false,
        public bool $immediate = true,
    ) {}
}
