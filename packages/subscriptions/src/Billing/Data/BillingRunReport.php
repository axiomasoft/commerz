<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Billing\Data;

use Packman\Attributes\Api;

/**
 * Outcome summary of running due subscription billing cycles.
 */
#[Api(since: '0.1')]
final readonly class BillingRunReport
{
    /**
     * @param  list<BillingCycle>  $cycles
     */
    public function __construct(
        public int $processed,
        public int $billed,
        public int $retrying,
        public int $exhausted,
        public array $cycles = [],
    ) {}
}
