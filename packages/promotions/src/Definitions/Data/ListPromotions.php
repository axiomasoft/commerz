<?php

declare(strict_types=1);

namespace Commerz\Promotions\Definitions\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;
use Commerz\Promotions\Definitions\Enums\PromotionStatus;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Stores\Data\ChannelId;

/** Promotions ordered by code, optionally of one status or stage or assigned to one channel. */
#[Api(since: '0.1')]
final readonly class ListPromotions
{
    public function __construct(
        public ?PromotionStatus $status = null,
        public ?PromotionStage $stage = null,
        public ?ChannelId $channel = null,
        public int $first = 100,
        public ?Cursor $after = null,
    ) {
        if ($first < 1 || $first > 200) {
            throw new InvalidCommand('first', 'must be from 1 to 200', 'common.invalid_page_size');
        }
    }
}
