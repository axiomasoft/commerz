<?php

declare(strict_types=1);

namespace Commerz\Shipping\Options\Internal;

use Commerz\Measures\LocalizedText;
use Commerz\Shipping\Methods\Enums\MethodStatus;

/**
 * A method that is not archived with one of its tariffs (or with none: `$rateId` null) and how well the tariff's zone
 * matches the address: `$rank` is the highest rank of the zone's matching members, null when the zone is archived or
 * none of its members matches.
 */
final readonly class CandidateRate
{
    /** @param  array<array-key, mixed>  $params  the raw `params` column */
    public function __construct(
        public string $methodCode,
        public LocalizedText $name,
        public string $carrier,
        public MethodStatus $status,
        public int $position,
        public bool $inChannel,
        public ?string $rateId,
        public ?string $currency,
        public ?string $rateKey,
        public ?int $rateVersion,
        public array $params,
        public ?int $freeAboveMinor,
        public bool $taxIncluded,
        public ?int $etaDays,
        public ?string $zoneCode,
        public ?int $rank,
    ) {}
}
