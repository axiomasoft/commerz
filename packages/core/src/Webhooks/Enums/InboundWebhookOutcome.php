<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum InboundWebhookOutcome: string
{
    case Processed = 'processed';
    case Duplicate = 'duplicate';
}
