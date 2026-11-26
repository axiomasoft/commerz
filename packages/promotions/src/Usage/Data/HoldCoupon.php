<?php

declare(strict_types=1);

namespace Commerz\Promotions\Usage\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Promotions\Common\Data\PromotionCodes;
use Packman\Attributes\Api;

/**
 * Places a TTL-bounded 2-phase hold on a coupon during checkout (requires `commerz-promotions.hold_on_checkout = true`).
 */
#[Api(since: '0.1')]
final readonly class HoldCoupon
{
    public string $couponCode;

    public string $holdRef;

    public function __construct(
        string $couponCode,
        string $holdRef,
        public ?CustomerRef $customer = null,
        public ?int $ttlSeconds = null,
    ) {
        $this->couponCode = PromotionCodes::couponCode('couponCode', $couponCode);
        $trimmedRef = trim($holdRef);

        if ($trimmedRef === '' || strlen($trimmedRef) > 191) {
            throw new InvalidCommand('holdRef', 'must be 1 to 191 bytes', 'promotions.invalid_usage');
        }

        if ($ttlSeconds !== null && ($ttlSeconds < 1 || $ttlSeconds > 86_400)) {
            throw new InvalidCommand('ttlSeconds', 'must be between 1 and 86400 seconds', 'promotions.invalid_usage');
        }

        $this->holdRef = $trimmedRef;
    }
}
