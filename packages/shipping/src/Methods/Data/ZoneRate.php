<?php

declare(strict_types=1);

namespace Commerz\Shipping\Methods\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Shipping\Rates\Data\RateSpec;
use Commerz\Shipping\Zones\Data\ZoneId;
use Packman\Attributes\Api;

/**
 * The tariff of a method in a zone and a currency. `$freeAbove` is the value of the goods after discounts from which
 * delivery is free (inclusive), in the currency of the tariff; `$taxIncluded` null takes `commerz-shipping.tax_included`.
 */
#[Api(since: '0.1')]
final readonly class ZoneRate
{
    public const int MAX_PER_METHOD = 500;

    public function __construct(
        public ZoneId $zone,
        public Currency $currency,
        public RateSpec $rate,
        public ?Money $freeAbove = null,
        public ?bool $taxIncluded = null,
        public ?int $etaDays = null,
    ) {
        if ($freeAbove instanceof Money) {
            if (! $freeAbove->currency->equals($currency)) {
                throw new InvalidCommand('freeAbove', "must be in {$currency->code}", 'shipping.currency_mismatch');
            }

            if ($freeAbove->isNegative()) {
                throw new InvalidCommand('freeAbove', 'must not be negative', 'shipping.invalid_free_above');
            }
        }

        if ($etaDays !== null && ($etaDays < 0 || $etaDays > 365)) {
            throw new InvalidCommand('etaDays', 'must be from 0 to 365', 'shipping.invalid_eta');
        }
    }

    /**
     * @param  list<self>  $rates
     * @return list<self> the same rates, in order
     *
     * @throws InvalidCommand when a (zone, currency) pair is listed twice or there are too many
     *
     * @internal
     */
    public static function assertValid(array $rates): array
    {
        if (count($rates) > self::MAX_PER_METHOD) {
            throw new InvalidCommand('rates', 'a method has at most '.self::MAX_PER_METHOD.' rates', 'shipping.too_many_rates');
        }

        $seen = [];

        foreach ($rates as $rate) {
            $key = $rate->zone->value.'|'.$rate->currency->code;

            if (isset($seen[$key])) {
                throw new InvalidCommand('rates', "zone {$rate->zone->value} in {$rate->currency->code} is listed twice", 'shipping.duplicate_rate');
            }

            $seen[$key] = true;
        }

        return $rates;
    }
}
