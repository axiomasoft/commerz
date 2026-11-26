<?php

declare(strict_types=1);

namespace Commerz\Promotions\Campaigns\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;
use Commerz\Promotions\Campaigns\Enums\CampaignStatus;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ListCampaigns
{
    public function __construct(
        public ?CampaignStatus $status = null,
        public int $first = 50,
        public ?Cursor $after = null,
    ) {
        if ($first < 1 || $first > 200) {
            throw new InvalidCommand('first', 'must be between 1 and 200', 'commerz.invalid_page_size');
        }
    }
}
