<?php

declare(strict_types=1);

namespace Commerz\Pricing\Markets\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;
use Commerz\Pricing\Markets\Enums\MarketStatus;
use Commerz\Stores\Data\ChannelId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ListMarkets
{
    public ?string $country;

    public function __construct(
        public ?MarketStatus $status = null,
        public ?ChannelId $channel = null,
        ?string $country = null,
        public int $first = 100,
        public ?Cursor $after = null,
    ) {
        if ($first < 1 || $first > 200) {
            throw new InvalidCommand('first', 'must be from 1 to 200', 'common.invalid_page_size');
        }

        if ($country !== null) {
            $cleaned = strtoupper(trim($country));

            if (preg_match('/\A[A-Z]{2}\z/', $cleaned) !== 1) {
                throw new InvalidCommand('country', "'{$country}' must be an ISO 3166-1 alpha-2 country code", 'pricing.invalid_countries');
            }

            $this->country = $cleaned;
        } else {
            $this->country = null;
        }
    }
}
