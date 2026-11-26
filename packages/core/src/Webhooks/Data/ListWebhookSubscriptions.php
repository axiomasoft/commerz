<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;
use Commerz\Webhooks\Enums\WebhookSubscriptionStatus;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ListWebhookSubscriptions
{
    public function __construct(
        public ?WebhookSubscriptionStatus $status = null,
        public int $first = 50,
        public ?Cursor $after = null,
    ) {
        if ($first < 1 || $first > 200) {
            throw new InvalidCommand('first', 'must be from 1 to 200', 'common.invalid_page_size');
        }
    }
}
