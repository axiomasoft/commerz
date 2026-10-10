<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use DateTimeImmutable;

/** Expires unpaid orders whose payment deadline is at or before `$at` (now when null), at most `$limit` of them (D32). */
#[Api(since: '0.1')]
final readonly class ExpireOrders
{
    /** @param  int|null  $limit  1..1000; null for `commerz-sales.orders.expire_batch` */
    public function __construct(public ?DateTimeImmutable $at = null, public ?int $limit = null)
    {
        if ($limit !== null && ($limit < 1 || $limit > 1000)) {
            throw new InvalidCommand('limit', 'must be from 1 to 1000', 'sales.invalid_limit');
        }
    }
}
