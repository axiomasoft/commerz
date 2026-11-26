<?php

declare(strict_types=1);

namespace Commerz\Pricing\Markets\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Pricing\PriceLists\Data\CreatePriceList;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use Packman\Attributes\Api;

/** Updates mutable attributes of a market; fields left null stay unchanged unless `$clearDefaultPriceList` is true. */
#[Api(since: '0.1')]
final readonly class UpdateMarket
{
    /** @var list<Currency>|null */
    public ?array $allowedCurrencies;

    /**
     * @param  list<Currency>|null  $allowedCurrencies
     */
    public function __construct(
        public MarketId $market,
        public ?string $name = null,
        public ?Currency $defaultCurrency = null,
        ?array $allowedCurrencies = null,
        public ?bool $taxIncluded = null,
        public ?PriceListId $defaultPriceListId = null,
        public bool $clearDefaultPriceList = false,
    ) {
        if ($name !== null) {
            CreatePriceList::assertName($name);
        }

        if ($clearDefaultPriceList && $defaultPriceListId instanceof PriceListId) {
            throw new InvalidCommand('defaultPriceListId', 'cannot set and clear defaultPriceListId simultaneously', 'pricing.invalid_code');
        }

        if ($allowedCurrencies !== null) {
            if ($allowedCurrencies === []) {
                throw new InvalidCommand('allowedCurrencies', 'must contain at least one currency', 'pricing.invalid_currencies');
            }

            $unique = [];

            foreach ($allowedCurrencies as $currency) {
                $unique[$currency->code] ??= $currency;
            }

            if ($defaultCurrency instanceof Currency && ! isset($unique[$defaultCurrency->code])) {
                throw new InvalidCommand(
                    'defaultCurrency',
                    "'{$defaultCurrency->code}' must be included in allowedCurrencies",
                    'pricing.invalid_currencies',
                );
            }

            $this->allowedCurrencies = array_values($unique);
        } else {
            $this->allowedCurrencies = null;
        }
    }
}
