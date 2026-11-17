<?php

declare(strict_types=1);

namespace Commerz\Shipping\Zones\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Shipping\Common\Data\PostalCode;
use Commerz\Shipping\Zones\Enums\MemberKind;
use Packman\Attributes\Api;

/** What a zone covers: a country, one region of it, or the postal codes of a country that start with a prefix. */
#[Api(since: '0.1')]
final readonly class ZoneMember
{
    private function __construct(
        public string $country,
        public ?string $region,
        public ?string $postalPrefix,
    ) {}

    /** @param  string  $country  ISO 3166-1 alpha-2, e.g. `RU` */
    public static function country(string $country): self
    {
        return new self(self::assertCountry($country), null, null);
    }

    /** @param  string  $region  ISO 3166-2 subdivision, e.g. `RU-MOW`; its country is the first two letters */
    public static function region(string $region): self
    {
        if (preg_match('/\A[A-Z]{2}-[A-Z0-9]{1,3}\z/', $region) !== 1) {
            throw new InvalidCommand('region', "'{$region}' is not an ISO 3166-2 code", 'shipping.invalid_zone_member');
        }

        return new self(substr($region, 0, 2), $region, null);
    }

    /** @param  string  $postalPrefix  upper-cased, without spaces and dashes (see PostalCode::normalize) */
    public static function postalPrefix(string $country, string $postalPrefix): self
    {
        $normalized = PostalCode::normalize($postalPrefix);

        if ($normalized === null) {
            throw new InvalidCommand('postal_prefix', "'{$postalPrefix}' is not a postal code prefix", 'shipping.invalid_zone_member');
        }

        return new self(self::assertCountry($country), null, $normalized);
    }

    public function kind(): MemberKind
    {
        return match (true) {
            $this->region !== null => MemberKind::Region,
            $this->postalPrefix !== null => MemberKind::PostalPrefix,
            default => MemberKind::Country,
        };
    }

    /**
     * @param  list<self>  $members
     * @return list<self> the same members, in order
     *
     * @throws InvalidCommand when one is listed twice
     *
     * @internal
     */
    public static function assertUnique(array $members): array
    {
        $seen = [];

        foreach ($members as $member) {
            $key = $member->country.'|'.($member->region ?? '').'|'.($member->postalPrefix ?? '');

            if (isset($seen[$key])) {
                throw new InvalidCommand('members', "'{$key}' is listed twice", 'shipping.duplicate_zone_member');
            }

            $seen[$key] = true;
        }

        return $members;
    }

    private static function assertCountry(string $country): string
    {
        if (preg_match('/\A[A-Z]{2}\z/', $country) !== 1) {
            throw new InvalidCommand('country', "'{$country}' is not an ISO 3166-1 alpha-2 code", 'shipping.invalid_zone_member');
        }

        return $country;
    }
}
