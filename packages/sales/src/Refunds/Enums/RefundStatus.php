<?php

declare(strict_types=1);

namespace Commerz\Sales\Refunds\Enums;

use Commerz\Attributes\Api;

/**
 * The status of one refund attempt (D33). `initiating` while the gateway is being asked, `pending` while the provider
 * works on it, `unknown` when its answer was lost (reconcile it). `succeeded` is final; `failed` frees the amount and
 * the gateway is not asked again, though a later success from the provider still settles it.
 */
#[Api(since: '0.1')]
enum RefundStatus: string
{
    case Initiating = 'initiating';
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unknown = 'unknown';

    /** Succeeded or failed: reconcile has nothing to ask the gateway. */
    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }
}
