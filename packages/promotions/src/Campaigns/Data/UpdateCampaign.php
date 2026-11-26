<?php

declare(strict_types=1);

namespace Commerz\Promotions\Campaigns\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Promotions\Definitions\Data\CreatePromotion;
use Commerz\Promotions\Definitions\Data\PromotionWindow;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class UpdateCampaign
{
    public function __construct(
        public CampaignId $campaign,
        public ?string $name = null,
        public ?Money $spendCap = null,
        public bool $clearSpendCap = false,
        public ?int $usageCap = null,
        public bool $clearUsageCap = false,
        public ?PromotionWindow $window = null,
    ) {
        if ($name !== null) {
            CreatePromotion::assertName($name);
        }

        CreatePromotion::assertBudget($spendCap);

        if ($clearSpendCap && $spendCap instanceof Money) {
            throw new InvalidCommand('spendCap', 'give a new spend cap or clear it, not both', 'promotions.invalid_update');
        }

        if ($usageCap !== null && ($usageCap < 1 || $usageCap > 2_147_483_647)) {
            throw new InvalidCommand('usageCap', 'must be a positive 32-bit integer', 'promotions.invalid_limits');
        }

        if ($clearUsageCap && $usageCap !== null) {
            throw new InvalidCommand('usageCap', 'give a new usage cap or clear it, not both', 'promotions.invalid_update');
        }
    }
}
