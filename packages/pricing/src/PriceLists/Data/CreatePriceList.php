<?php

declare(strict_types=1);

namespace Commerz\Pricing\PriceLists\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Pricing\Common\Data\PricingCodes;
use Commerz\Pricing\PriceLists\Enums\TaxInclusion;
use Commerz\Stores\Data\ChannelId;
use Packman\Attributes\Api;

/** A price list in one currency; every channel must sell in that currency. The currency never changes afterwards. */
#[Api(since: '0.1')]
final readonly class CreatePriceList
{
    /** @param  list<ChannelId>  $channels */
    public function __construct(
        public string $code,
        public string $name,
        public Currency $currency,
        public array $channels = [],
        public int $priority = 0,
        public TaxInclusion $taxInclusion = TaxInclusion::AsChannel,
    ) {
        PricingCodes::code('code', $code);
        self::assertName($name);
        self::assertPriority($priority);
    }

    /** @internal */
    public static function assertName(string $name): void
    {
        if (trim($name) === '' || strlen($name) > 255) {
            throw new InvalidCommand('name', 'must be 1 to 255 bytes', 'pricing.invalid_name');
        }
    }

    /** @internal the column is a 32-bit integer */
    public static function assertPriority(int $priority): void
    {
        if ($priority < -2_147_483_648 || $priority > 2_147_483_647) {
            throw new InvalidCommand('priority', 'must fit a 32-bit integer', 'pricing.invalid_priority');
        }
    }
}
