<?php

declare(strict_types=1);

namespace Commerz\Stores\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Stores\Exceptions\CurrencyNotAllowed;

/**
 * The first currency and the first locale are the defaults unless `defaultCurrency` / `defaultLocale` say otherwise.
 */
#[Api(since: '0.1')]
final readonly class CreateChannel
{
    public Currency $defaultCurrency;

    public string $defaultLocale;

    /**
     * @param  list<Currency>  $currencies
     * @param  list<string>  $locales
     */
    public function __construct(
        public StoreId $store,
        public string $code,
        public string $name,
        public array $currencies,
        public array $locales = ['en'],
        public bool $pricesIncludeTax = false,
        ?Currency $defaultCurrency = null,
        ?string $defaultLocale = null,
    ) {
        Codes::code($code);
        Codes::name($name);
        ChannelSettings::assertCurrencies($currencies);
        ChannelSettings::assertLocales($locales);

        $this->defaultCurrency = $defaultCurrency ?? $currencies[0];
        $this->defaultLocale = $defaultLocale ?? $locales[0];

        if (! ChannelSettings::hasCurrency($currencies, $this->defaultCurrency)) {
            throw new CurrencyNotAllowed($code, $this->defaultCurrency->code);
        }

        if (! in_array($this->defaultLocale, $locales, true)) {
            throw new InvalidCommand('defaultLocale', "'{$this->defaultLocale}' is not among the channel locales", 'stores.invalid_locale');
        }
    }
}
