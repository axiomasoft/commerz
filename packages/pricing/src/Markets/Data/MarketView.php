<?php

declare(strict_types=1);

namespace Commerz\Pricing\Markets\Data;

use Commerz\Money\Currency;
use Commerz\Pricing\Markets\Enums\MarketStatus;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class MarketView
{
    /**
     * @param  list<string>  $countries  sorted ISO-2 country codes
     * @param  list<Currency>  $allowedCurrencies
     */
    public function __construct(
        public MarketId $id,
        public string $code,
        public string $name,
        public ChannelId $channel,
        public array $countries,
        public Currency $defaultCurrency,
        public array $allowedCurrencies,
        public bool $taxIncluded,
        public ?PriceListId $defaultPriceListId,
        public MarketStatus $status,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    public function allowsCurrency(Currency $currency): bool
    {
        return array_any($this->allowedCurrencies, static fn (Currency $allowed): bool => $allowed->equals($currency));
    }

    public function servesCountry(string $country): bool
    {
        return in_array(strtoupper(trim($country)), $this->countries, true);
    }
}
