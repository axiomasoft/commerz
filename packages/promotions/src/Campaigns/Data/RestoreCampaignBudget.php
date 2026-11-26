<?php

declare(strict_types=1);

namespace Commerz\Promotions\Campaigns\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class RestoreCampaignBudget
{
    public function __construct(
        public CampaignId $campaign,
        public Money $discount,
        public int $uses = 1,
    ) {
        if ($discount->isNegative()) {
            throw new InvalidCommand('discount', 'must not be negative', 'promotions.invalid_usage');
        }

        if ($uses < 1 || $uses > 2_147_483_647) {
            throw new InvalidCommand('uses', 'must be a positive 32-bit integer', 'promotions.invalid_usage');
        }
    }
}
