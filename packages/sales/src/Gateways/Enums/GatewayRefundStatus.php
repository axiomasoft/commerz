<?php

declare(strict_types=1);

namespace Commerz\Sales\Gateways\Enums;

use Commerz\Attributes\Api;

#[Api(since: '0.1')]
enum GatewayRefundStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
