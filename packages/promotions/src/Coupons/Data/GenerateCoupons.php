<?php

declare(strict_types=1);

namespace Commerz\Promotions\Coupons\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\IdempotencyKey;
use Commerz\Promotions\Coupons\Internal\CouponLimits;
use Commerz\Promotions\Definitions\Data\PromotionId;

/**
 * A batch of `$count` random codes of one pattern for a `coupon` promotion. In the pattern `#` stands for a character
 * of the configured alphabet and any other character is kept (`[A-Z0-9_-]`, upper-cased): `AUTUMN-####-####`. With a
 * key the command is idempotent: a retry returns the same batch.
 */
#[Api(since: '0.1')]
final readonly class GenerateCoupons
{
    public function __construct(
        public PromotionId $promotion,
        public int $count,
        public string $pattern,
        public ?int $usageLimit = 1,
        public ?IdempotencyKey $idempotencyKey = null,
    ) {
        if ($count < 1) {
            throw new InvalidCommand('count', 'must be at least 1', 'promotions.invalid_count');
        }

        CouponLimits::assert('usageLimit', $usageLimit);
    }
}
