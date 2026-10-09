<?php

declare(strict_types=1);

namespace Commerz\Shipping\Zones\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;
use Commerz\Shipping\Zones\Enums\ZoneStatus;

/** Zones ordered by code, optionally of one status or having a member in one country. */
#[Api(since: '0.1')]
final readonly class ListZones
{
    public function __construct(
        public ?ZoneStatus $status = null,
        public ?string $country = null,
        public ?Cursor $after = null,
        public int $limit = 50,
    ) {
        if ($country !== null && preg_match('/\A[A-Z]{2}\z/', $country) !== 1) {
            throw new InvalidCommand('country', "'{$country}' is not an ISO 3166-1 alpha-2 code", 'shipping.invalid_zone_member');
        }

        if ($limit < 1 || $limit > 200) {
            throw new InvalidCommand('limit', 'must be from 1 to 200', 'common.invalid_page_size');
        }
    }
}
