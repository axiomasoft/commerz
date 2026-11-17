<?php

declare(strict_types=1);

namespace Commerz\Pricing\Taxes\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/** A country, or one region of it, that belongs to a tax zone. A pair belongs to at most one zone (TAX-01). */
#[Api(since: '0.1')]
final readonly class TaxZoneMember
{
    /**
     * @param  string  $country  ISO 3166-1 alpha-2, e.g. `RU`
     * @param  string|null  $region  ISO 3166-2 subdivision, e.g. `RU-MOW`; null covers the country outside its own regions
     */
    public function __construct(public string $country, public ?string $region = null)
    {
        if (preg_match('/\A[A-Z]{2}\z/', $country) !== 1) {
            throw new InvalidCommand('country', "'{$country}' is not an ISO 3166-1 alpha-2 code", 'pricing.invalid_member');
        }

        if ($region !== null && preg_match('/\A'.$country.'-[A-Z0-9]{1,3}\z/', $region) !== 1) {
            throw new InvalidCommand('region', "'{$region}' is not an ISO 3166-2 code of {$country}", 'pricing.invalid_member');
        }
    }

    /**
     * @param  list<self>  $members
     * @return list<self> without repeats, sorted by country and region (a missing region first)
     *
     * @internal
     */
    public static function normalize(array $members): array
    {
        $unique = [];

        foreach ($members as $member) {
            $key = $member->country.'|'.($member->region ?? '');

            if (isset($unique[$key])) {
                throw new InvalidCommand('members', "'{$key}' is listed twice", 'pricing.duplicate_member');
            }

            $unique[$key] = $member;
        }

        ksort($unique, SORT_STRING);

        return array_values($unique);
    }
}
