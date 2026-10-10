<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Data;

use Commerz\Attributes\Api;

/** The outcome of one expiry pass. */
#[Api(since: '0.1')]
final readonly class ExpireReport
{
    /**
     * @param  list<OrderId>  $expired  cancelled with the reason `expired`, in deadline order
     * @param  int  $skipped  eligible candidates skipped due to locks or a concurrent state change; known ineligible orders are excluded
     */
    public function __construct(public array $expired, public int $skipped) {}
}
