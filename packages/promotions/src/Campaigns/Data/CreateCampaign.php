<?php

declare(strict_types=1);

namespace Commerz\Promotions\Campaigns\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Promotions\Campaigns\Enums\CampaignStatus;
use Commerz\Promotions\Common\Data\PromotionCodes;
use Commerz\Promotions\Definitions\Data\CreatePromotion;
use Commerz\Promotions\Definitions\Data\PromotionWindow;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class CreateCampaign
{
    public function __construct(
        public string $code,
        public string $name,
        public ?Currency $currency = null,
        public ?Money $spendCap = null,
        public ?int $usageCap = null,
        public ?DateTimeImmutable $startsAt = null,
        public ?DateTimeImmutable $endsAt = null,
        public CampaignStatus $status = CampaignStatus::Active,
    ) {
        PromotionCodes::code('code', $code);
        CreatePromotion::assertName($name);
        CreatePromotion::assertBudget($spendCap);
        new PromotionWindow($startsAt, $endsAt);

        if ($usageCap !== null && ($usageCap < 1 || $usageCap > 2_147_483_647)) {
            throw new InvalidCommand('usageCap', 'must be a positive 32-bit integer', 'promotions.invalid_limits');
        }

        if ($status === CampaignStatus::Archived) {
            throw new InvalidCommand('status', 'a campaign is created active or paused', 'promotions.invalid_status');
        }

        if ($spendCap instanceof Money && $currency instanceof Currency && ! $spendCap->currency->equals($currency)) {
            throw new InvalidCommand('spendCap', "must be in {$currency->code}", 'promotions.currency_mismatch');
        }
    }
}
