<?php

declare(strict_types=1);

namespace Commerz\Promotions\Campaigns\Data;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ArchiveCampaign
{
    public function __construct(public CampaignId $campaign) {}
}
