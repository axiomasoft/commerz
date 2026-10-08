<?php

declare(strict_types=1);

namespace Commerz\Stores\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;

/** List checks shared by the channel commands. */
final class ChannelSettings
{
    /** @param  list<Currency>  $currencies */
    public static function assertCurrencies(array $currencies): void
    {
        if ($currencies === []) {
            throw new InvalidCommand('currencies', 'must be a non-empty list', 'stores.invalid_currencies');
        }

        $codes = array_map(static fn (Currency $currency): string => $currency->code, $currencies);

        if (count(array_unique($codes)) !== count($codes)) {
            throw new InvalidCommand('currencies', 'must not repeat a currency', 'stores.invalid_currencies');
        }
    }

    /** @param  list<string>  $locales */
    public static function assertLocales(array $locales): void
    {
        if ($locales === []) {
            throw new InvalidCommand('locales', 'must be a non-empty list', 'stores.invalid_locale');
        }

        foreach ($locales as $locale) {
            Codes::locale($locale);
        }

        if (count(array_unique($locales)) !== count($locales)) {
            throw new InvalidCommand('locales', 'must not repeat a locale', 'stores.invalid_locale');
        }
    }

    /** @param  list<Currency>  $currencies */
    public static function hasCurrency(array $currencies, Currency $currency): bool
    {
        return array_any($currencies, fn ($candidate) => $candidate->equals($currency));
    }
}
