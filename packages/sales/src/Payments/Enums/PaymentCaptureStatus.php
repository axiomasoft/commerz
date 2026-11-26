<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Enums;

use Packman\Attributes\Api;

/** Lifecycle state of one capture attempt against a payment session (supports multicapture). */
#[Api(since: '0.1')]
enum PaymentCaptureStatus: string
{
    case Initiating = 'initiating';
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unknown = 'unknown';
}
