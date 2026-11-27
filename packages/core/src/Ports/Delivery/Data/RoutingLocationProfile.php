<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Ports\Stock\Data\StockLines;
use Packman\Attributes\Api;

/**
 * Routing profile of a fulfillment location: physical capabilities, tags, geographic coordinates,
 * served markets, and daily assignment quota state.
 */
#[Api(since: '0.1')]
final readonly class RoutingLocationProfile
{
    /** @var list<string> */
    public array $tags;

    /** @var list<string> */
    public array $marketCodes;

    /**
     * @param  list<string>  $tags  location capability tags, e.g. `hazmat`, `bonded`
     * @param  list<string>  $marketCodes  market or country codes served by this location, e.g. `DE`, `EU`
     */
    public function __construct(
        public string $locationCode,
        public bool $coldChainCapable = false,
        public bool $oversizedCapable = false,
        array $tags = [],
        public ?GeoPoint $coordinates = null,
        array $marketCodes = [],
        public ?int $dailyQuota = null,
        public int $dailyAssignedCount = 0,
    ) {
        if ($locationCode === '') {
            throw new InvalidCommand('locationCode', 'must not be empty', 'delivery.invalid_location_profile');
        }

        StockLines::assertLocation($locationCode);

        foreach ($tags as $tag) {
            if (preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/', $tag) !== 1) {
                throw new InvalidCommand('tags', "tag '{$tag}' must match [a-z0-9][a-z0-9_.-]{0,63}", 'delivery.invalid_location_profile');
            }
        }

        foreach ($marketCodes as $marketCode) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $marketCode) !== 1) {
                throw new InvalidCommand('marketCodes', "market '{$marketCode}' must match [A-Za-z0-9][A-Za-z0-9_.-]{0,63}", 'delivery.invalid_location_profile');
            }
        }

        if ($dailyQuota !== null && $dailyQuota < 0) {
            throw new InvalidCommand('dailyQuota', 'must not be negative', 'delivery.invalid_location_profile');
        }

        if ($dailyAssignedCount < 0) {
            throw new InvalidCommand('dailyAssignedCount', 'must not be negative', 'delivery.invalid_location_profile');
        }

        $this->tags = array_values(array_unique($tags));
        $this->marketCodes = array_values(array_unique(array_map(strtoupper(...), $marketCodes)));
    }

    /** Whether this location satisfies every capability requirement of `$constraints`. */
    public function satisfies(FulfillmentConstraints $constraints): bool
    {
        if ($constraints->coldChain && ! $this->coldChainCapable) {
            return false;
        }

        if ($constraints->oversized && ! $this->oversizedCapable) {
            return false;
        }

        return array_all($constraints->requiredLocationTags, fn (string $tag): bool => in_array($tag, $this->tags, true));
    }

    public function hasDailyQuotaRemaining(): bool
    {
        return $this->dailyQuota === null || $this->dailyAssignedCount < $this->dailyQuota;
    }

    public function remainingDailyQuota(): ?int
    {
        return $this->dailyQuota === null ? null : max(0, $this->dailyQuota - $this->dailyAssignedCount);
    }

    public function servesMarket(string $marketCode): bool
    {
        return in_array(strtoupper($marketCode), $this->marketCodes, true);
    }
}
