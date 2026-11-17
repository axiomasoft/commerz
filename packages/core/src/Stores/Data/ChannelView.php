<?php

declare(strict_types=1);

namespace Commerz\Stores\Data;

use Commerz\Money\Currency;
use Commerz\Stores\Enums\ChannelStatus;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ChannelView
{
    /**
     * @param  list<Currency>  $currencies
     * @param  list<string>  $locales
     */
    public function __construct(
        public ChannelId $id,
        public StoreId $store,
        public string $code,
        public string $name,
        public array $currencies,
        public Currency $defaultCurrency,
        public array $locales,
        public string $defaultLocale,
        public bool $pricesIncludeTax,
        public ChannelStatus $status,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    public function allows(Currency $currency): bool
    {
        return ChannelSettings::hasCurrency($this->currencies, $currency);
    }
}
