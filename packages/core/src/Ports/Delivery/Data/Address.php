<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;

#[Api(since: '0.1')]
final readonly class Address
{
    /**
     * @param  string  $country  ISO 3166-1 alpha-2, e.g. `RU`
     * @param  string|null  $region  ISO 3166-2 subdivision, e.g. `RU-MOW`
     * @param  list<string>  $lines  street, building, apartment
     */
    public function __construct(
        public string $country,
        public ?string $region = null,
        public ?string $city = null,
        public ?string $postalCode = null,
        public array $lines = [],
        public ?string $recipient = null,
        public ?string $phone = null,
    ) {
        if (preg_match('/\A[A-Z]{2}\z/', $country) !== 1) {
            throw new InvalidCommand('country', "'{$country}' is not an ISO 3166-1 alpha-2 code", 'delivery.invalid_address');
        }

        if ($region !== null && preg_match('/\A'.$country.'-[A-Z0-9]{1,3}\z/', $region) !== 1) {
            throw new InvalidCommand('region', "'{$region}' is not an ISO 3166-2 code of {$country}", 'delivery.invalid_address');
        }
    }
}
