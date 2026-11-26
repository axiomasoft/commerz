<?php

declare(strict_types=1);

namespace Commerz\Promotions\Campaigns\Exceptions;

use Commerz\Errors\NotFound;
use Commerz\Promotions\Errors\Enums\PromotionsErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class CampaignNotFound extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('Campaign', $id, 'promotions.campaign_not_found');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: PromotionsErrorCode::CampaignNotFound,
            category: ErrorCategory::NotFound,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
