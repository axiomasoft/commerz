<?php

declare(strict_types=1);

namespace Commerz\Promotions\Campaigns\Events;

use Commerz\Promotions\Campaigns\Data\CampaignId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class CampaignChanged
{
    public function __construct(public CampaignId $campaign) {}
}
