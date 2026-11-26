<?php

declare(strict_types=1);

namespace Commerz\Promotions\Campaigns\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum CampaignStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';
}
