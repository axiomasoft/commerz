<?php

declare(strict_types=1);

namespace Commerz\Pricing\Taxes\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** A country, or a region of it, already belongs to another tax zone (TAX-01). */
#[Api(since: '0.1')]
final class TaxZoneMemberTaken extends CommerzException
{
    public function __construct(
        public readonly string $country,
        public readonly ?string $region,
        public readonly ?string $otherZone,
    ) {
        $place = $region ?? $country;

        parent::__construct("'{$place}' already belongs to tax zone '".($otherZone ?? '?')."'.");
    }

    public function code(): string
    {
        return 'pricing.tax_zone_member_taken';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['country' => $this->country, 'region' => $this->region, 'zone' => $this->otherZone];
    }
}
