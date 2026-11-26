<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum WebhookDeliveryStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Failed = 'failed';
}
