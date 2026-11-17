<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Packman\Attributes\Api;

/**
 * One promotion an activator switched on. `couponCode` is an owned package coupon counted at placement;
 * `presentedCode` may instead be a third-party activation code with no package coupon.
 */
#[Api(since: '0.1')]
final readonly class Activation
{
    public function __construct(
        public PromotionId $promotion,
        public ?string $couponCode = null,
        public ?string $presentedCode = null,
    ) {
        if ($couponCode === '' || $presentedCode === '') {
            throw new InvalidCommand('couponCode', 'must be null or non-empty', 'promotions.invalid_activation');
        }
    }
}
