<?php

declare(strict_types=1);

namespace Commerz\Stores\Data;

use Commerz\Attributes\Api;
use Commerz\Money\Currency;

/**
 * Changes the given fields; a null field stays as it is. The code and store never change. The resulting default
 * currency and locale must stay inside the resulting lists; the service checks that against the stored channel.
 */
#[Api(since: '0.1')]
final readonly class UpdateChannel
{
    /**
     * @param  list<Currency>|null  $currencies
     * @param  list<string>|null  $locales
     */
    public function __construct(
        public ChannelId $channel,
        public ?string $name = null,
        public ?array $currencies = null,
        public ?array $locales = null,
        public ?bool $pricesIncludeTax = null,
        public ?Currency $defaultCurrency = null,
        public ?string $defaultLocale = null,
    ) {
        if ($name !== null) {
            Codes::name($name);
        }

        if ($currencies !== null) {
            ChannelSettings::assertCurrencies($currencies);
        }

        if ($locales !== null) {
            ChannelSettings::assertLocales($locales);
        }

        if ($defaultLocale !== null) {
            Codes::locale($defaultLocale);
        }
    }
}
