<?php

declare(strict_types=1);

namespace Commerz\Promotions\Coupons\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;
use Commerz\Promotions\Coupons\Enums\CouponStatus;
use Commerz\Promotions\Definitions\Data\PromotionId;

/** The coupons of a promotion ordered by code, optionally of one batch or status. */
#[Api(since: '0.1')]
final readonly class ListCoupons
{
    public function __construct(
        public PromotionId $promotion,
        public ?CouponBatchId $batch = null,
        public ?CouponStatus $status = null,
        public int $first = 100,
        public ?Cursor $after = null,
    ) {
        if ($first < 1 || $first > 500) {
            throw new InvalidCommand('first', 'must be from 1 to 500', 'common.invalid_page_size');
        }
    }
}
