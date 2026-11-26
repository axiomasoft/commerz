<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum WebhookSubscriptionStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Disabled = 'disabled';
}
