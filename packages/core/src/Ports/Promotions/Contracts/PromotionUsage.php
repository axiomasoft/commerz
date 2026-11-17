<?php

declare(strict_types=1);

namespace Commerz\Ports\Promotions\Contracts;

use Commerz\Ports\Promotions\Data\ConsumePromotionUsage;
use Commerz\Ports\Promotions\Data\RestorePromotionUsage;
use Commerz\Ports\Promotions\Exceptions\UsageLimitReached;
use Packman\Attributes\Api;

/**
 * Counts promotion, coupon and budget usage of placed orders. Both mutations join the caller's database transaction
 * on the Commerz connection (or open their own when there is none), lock counters in a fixed order and make no
 * network calls; a limit failure rolls the whole placement back.
 */
#[Api(since: '0.1')]
interface PromotionUsage
{
    /**
     * Idempotent per order ref.
     *
     * @throws UsageLimitReached
     */
    public function consume(ConsumePromotionUsage $command): void;

    /** Gives usage of an order back when the restore policy allows the reason; idempotent per order ref. */
    public function restore(RestorePromotionUsage $command): void;
}
