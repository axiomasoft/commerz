<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Promotions\Definitions\Data\PromotionId;

/** One promotion an activator switched on, and the code (canonical, and as presented) that did it, if any. */
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
