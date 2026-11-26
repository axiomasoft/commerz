<?php

declare(strict_types=1);

namespace Commerz\Promotions\Campaigns\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Promotions\Errors\Enums\PromotionsErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class CampaignArchived extends CommerzException
{
    public function __construct(public readonly string $campaign)
    {
        parent::__construct("Campaign '{$campaign}' is archived and cannot be changed.");
    }

    public function code(): string
    {
        return 'promotions.campaign_archived';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['campaign' => $this->campaign];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: PromotionsErrorCode::CampaignArchived,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
