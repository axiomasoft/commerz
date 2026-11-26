<?php

declare(strict_types=1);

namespace Commerz\Catalog\Projection\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;
use Commerz\Stores\Data\ChannelId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ListStorefrontProducts
{
    public function __construct(
        public ChannelId $channel,
        public string $locale,
        public ?bool $inStock = null,
        public ?int $minPriceMinor = null,
        public ?int $maxPriceMinor = null,
        public int $first = 24,
        public ?Cursor $after = null,
    ) {
        if (trim($locale) === '') {
            throw new InvalidCommand('locale', 'must not be empty');
        }

        if ($first < 1 || $first > 200) {
            throw new InvalidCommand('first', 'must be from 1 to 200', 'common.invalid_page_size');
        }

        if ($minPriceMinor !== null && $minPriceMinor < 0) {
            throw new InvalidCommand('minPriceMinor', 'must not be negative');
        }

        if ($maxPriceMinor !== null && $maxPriceMinor < 0) {
            throw new InvalidCommand('maxPriceMinor', 'must not be negative');
        }

        if ($minPriceMinor !== null && $maxPriceMinor !== null && $maxPriceMinor < $minPriceMinor) {
            throw new InvalidCommand('maxPriceMinor', 'must be greater than or equal to minPriceMinor');
        }
    }
}
