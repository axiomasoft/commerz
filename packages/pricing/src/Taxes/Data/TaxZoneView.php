<?php

declare(strict_types=1);

namespace Commerz\Pricing\Taxes\Data;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class TaxZoneView
{
    /** @param  list<TaxZoneMember>  $members sorted by country and region, a missing region first */
    public function __construct(public TaxZoneId $id, public string $code, public string $name, public array $members) {}
}
