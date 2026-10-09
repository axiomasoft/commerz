<?php

declare(strict_types=1);

namespace Commerz\Pricing\Taxes\Data;

use Commerz\Attributes\Api;
use Commerz\Pricing\Common\Data\PricingCodes;
use Commerz\Pricing\PriceLists\Data\CreatePriceList;

/** A tax class: the group of goods that share rates (`standard`, `reduced`, `books`). The code is unique. */
#[Api(since: '0.1')]
final readonly class DefineTaxClass
{
    public function __construct(public string $code, public string $name)
    {
        PricingCodes::code('code', $code);
        CreatePriceList::assertName($name);
    }
}
