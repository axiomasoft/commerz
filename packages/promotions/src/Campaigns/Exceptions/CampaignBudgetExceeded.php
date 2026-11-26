<?php

declare(strict_types=1);

namespace Commerz\Promotions\Campaigns\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Promotions\Errors\Enums\PromotionsErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Raised when consuming a campaign's spend or usage cap would breach `spend_cap_minor` or `usage_cap` (INV-PRM-001).
 */
#[Api(since: '0.1')]
final class CampaignBudgetExceeded extends CommerzException
{
    public const string SPEND_CAP = 'campaign_budget';

    public const string USAGE_CAP = 'campaign_usage';

    public function __construct(
        public readonly string $campaign,
        public readonly string $limit,
    ) {
        parent::__construct("Campaign '{$campaign}' exceeded its {$limit} cap.");
    }

    public function code(): string
    {
        return 'promotions.campaign_budget_exceeded';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::RateLimit;
    }

    public function context(): array
    {
        return ['campaign' => $this->campaign, 'limit' => $this->limit];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: PromotionsErrorCode::CampaignBudgetExceeded,
            category: ErrorCategory::RateLimit,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
