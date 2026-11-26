<?php

declare(strict_types=1);

namespace Commerz\Pricing\Markets\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Pricing\Common\Data\PricingCodes;
use Commerz\Pricing\PriceLists\Data\CreatePriceList;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use Commerz\Stores\Data\ChannelId;
use Packman\Attributes\Api;

/** Defines a regional market linked to a channel with its countries, currencies, tax inclusion, and default price list. */
#[Api(since: '0.1')]
final readonly class CreateMarket
{
    /** @var list<string> */
    public array $countries;

    /** @var list<Currency> */
    public array $allowedCurrencies;

    /**
     * @param  list<string>  $countries  ISO 3166-1 alpha-2 country codes
     * @param  list<Currency>  $allowedCurrencies  defaults to `[$defaultCurrency]` when empty
     */
    public function __construct(
        public string $code,
        public string $name,
        public ChannelId $channel,
        array $countries,
        public Currency $defaultCurrency,
        array $allowedCurrencies = [],
        public bool $taxIncluded = false,
        public ?PriceListId $defaultPriceListId = null,
    ) {
        PricingCodes::code('code', $code);
        CreatePriceList::assertName($name);

        $this->countries = self::normalizeCountries($countries);
        $this->allowedCurrencies = self::normalizeCurrencies($defaultCurrency, $allowedCurrencies === [] ? [$defaultCurrency] : $allowedCurrencies);
    }

    /**
     * @param  list<string>  $countries
     * @return list<string> sorted unique ISO-2 codes
     *
     * @internal
     */
    public static function normalizeCountries(array $countries): array
    {
        if ($countries === []) {
            throw new InvalidCommand('countries', 'must contain at least one country', 'pricing.invalid_countries');
        }

        $normalized = [];

        foreach ($countries as $country) {
            $cleaned = strtoupper(trim($country));

            if (preg_match('/\A[A-Z]{2}\z/', $cleaned) !== 1) {
                throw new InvalidCommand('countries', "'{$country}' must be a two-letter ISO 3166-1 alpha-2 country code", 'pricing.invalid_countries');
            }

            $normalized[$cleaned] = $cleaned;
        }

        ksort($normalized);

        return array_values($normalized);
    }

    /**
     * @param  list<Currency>  $currencies
     * @return list<Currency> unique currencies containing `$defaultCurrency`
     *
     * @internal
     */
    public static function normalizeCurrencies(Currency $defaultCurrency, array $currencies): array
    {
        if ($currencies === []) {
            throw new InvalidCommand('allowedCurrencies', 'must contain at least one currency', 'pricing.invalid_currencies');
        }

        $byCode = [];

        foreach ($currencies as $currency) {
            $byCode[$currency->code] ??= $currency;
        }

        if (! isset($byCode[$defaultCurrency->code])) {
            throw new InvalidCommand(
                'defaultCurrency',
                "'{$defaultCurrency->code}' must be included in allowedCurrencies",
                'pricing.invalid_currencies',
            );
        }

        return array_values($byCode);
    }
}
