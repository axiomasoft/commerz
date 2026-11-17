<?php

declare(strict_types=1);

namespace Commerz\Pricing\Taxes\Data;

use Commerz\Pricing\Common\Data\PricingCodes;
use Commerz\Pricing\PriceLists\Data\CreatePriceList;
use Packman\Attributes\Api;

/** A tax zone: the countries and regions that share one set of rates. The code is unique. */
#[Api(since: '0.1')]
final readonly class DefineTaxZone
{
    /** @var list<TaxZoneMember> without repeats, sorted by country and region */
    public array $members;

    /** @param  list<TaxZoneMember>  $members */
    public function __construct(public string $code, public string $name, array $members = [])
    {
        PricingCodes::code('code', $code);
        CreatePriceList::assertName($name);
        $this->members = TaxZoneMember::normalize($members);
    }
}
