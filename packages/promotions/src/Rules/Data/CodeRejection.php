<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Packman\Attributes\Api;

/** Why a presented code switched nothing on. */
#[Api(since: '0.1')]
final readonly class CodeRejection
{
    /**
     * @param  string  $code  as presented
     * @param  string  $reason  snake_case code, e.g. `unknown_code` or `coupon_disabled`
     */
    public function __construct(
        public string $code,
        public string $reason,
        public string $message,
        public ?PromotionId $promotion = null,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $reason) !== 1) {
            throw new InvalidCommand('reason', "'{$reason}' must be a snake_case code", 'promotions.invalid_activation');
        }
    }
}
