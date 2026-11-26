<?php

declare(strict_types=1);

namespace Commerz\Promotions\Campaigns\Data;

use Commerz\Money\Currency;
use Commerz\Promotions\Campaigns\Enums\CampaignStatus;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * A promotion campaign grouping promotions under a shared schedule and {@see CampaignBudget}.
 */
#[Api(since: '0.1')]
final readonly class Campaign
{
    public function __construct(
        public CampaignId $id,
        public string $code,
        public string $name,
        public CampaignStatus $status,
        public ?Currency $currency,
        public CampaignBudget $budget,
        public ?DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    public function isActiveAt(DateTimeImmutable $instant): bool
    {
        if ($this->status !== CampaignStatus::Active) {
            return false;
        }

        if ($this->startsAt instanceof DateTimeImmutable && $instant < $this->startsAt) {
            return false;
        }

        return ! ($this->endsAt instanceof DateTimeImmutable && $instant >= $this->endsAt);
    }
}
