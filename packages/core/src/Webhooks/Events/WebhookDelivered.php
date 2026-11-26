<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Events;

use Commerz\Webhooks\Data\WebhookDeliveryView;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class WebhookDelivered
{
    public function __construct(public WebhookDeliveryView $delivery) {}
}
