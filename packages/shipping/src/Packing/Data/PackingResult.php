<?php

declare(strict_types=1);

namespace Commerz\Shipping\Packing\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\Parcel;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class PackingResult
{
    /** @var non-empty-list<PackedParcel> */
    public array $parcels;

    /** @param  list<PackedParcel>  $parcels */
    public function __construct(array $parcels)
    {
        if ($parcels === []) {
            throw new InvalidCommand('parcels', 'must contain at least one packed parcel', 'shipping.invalid_packing');
        }

        $this->parcels = $parcels;
    }

    /** @return non-empty-list<Parcel> */
    public function toParcels(): array
    {
        return array_map(static fn (PackedParcel $packed): Parcel => $packed->parcel, $this->parcels);
    }

    public function totalPhysicalWeightGrams(): int
    {
        return array_sum(array_map(static fn (PackedParcel $packed): int => $packed->physicalWeightGrams, $this->parcels));
    }

    public function totalVolumetricWeightGrams(): int
    {
        return array_sum(array_map(static fn (PackedParcel $packed): int => $packed->volumetricWeightGrams, $this->parcels));
    }

    public function totalChargeableWeightGrams(): int
    {
        return array_sum(array_map(static fn (PackedParcel $packed): int => $packed->chargeableWeightGrams, $this->parcels));
    }

    public function totalValue(): Money
    {
        $currency = $this->parcels[0]->parcel->value->currency;
        $total = Money::zero($currency);

        foreach ($this->parcels as $packed) {
            $total = $total->plus($packed->parcel->value);
        }

        return $total;
    }
}
