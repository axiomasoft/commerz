<?php

declare(strict_types=1);

namespace Commerz\Pricing\Taxes\Data;

use Commerz\Attributes\Api;
use Commerz\Pricing\Common\Data\PricingCodes;

/** Replaces the members of a zone; a pair that belongs to another zone is refused (TAX-01). */
#[Api(since: '0.1')]
final readonly class SetTaxZoneMembers
{
    /** @var list<TaxZoneMember> without repeats, sorted by country and region */
    public array $members;

    /**
     * @param  string  $zone  zone code
     * @param  list<TaxZoneMember>  $members
     */
    public function __construct(public string $zone, array $members)
    {
        PricingCodes::code('zone', $zone);
        $this->members = TaxZoneMember::normalize($members);
    }
}
