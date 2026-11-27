<?php

declare(strict_types=1);

namespace Commerz\Shipping\Packing\Internal;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Commerz\Money\Money;
use Commerz\Money\MoneyAllocator;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Ports\Delivery\Data\PickupPointLimits;
use Commerz\Shipping\Packing\Contracts\ParcelPacker;
use Commerz\Shipping\Packing\Data\PackableItem;
use Commerz\Shipping\Packing\Data\PackedItem;
use Commerz\Shipping\Packing\Data\PackedParcel;
use Commerz\Shipping\Packing\Data\PackingConstraints;
use Commerz\Shipping\Packing\Data\PackingRequest;
use Commerz\Shipping\Packing\Data\PackingResult;
use Commerz\Shipping\Packing\Exceptions\UnpackableItem;
use Packman\Attributes\Internal;

/**
 * Default deterministic First-Fit Decreasing (FFD) packer that accounts for both physical weight (grams) and
 * volumetric weight (`ceil(mm³ / divisor)` via `brick/math`, zero floats), splitting heavy or bulky orders into 2+
 * parcels while preserving exact minor-unit monetary totals.
 */
#[Internal]
final readonly class WeightAndVolumeParcelPacker implements ParcelPacker
{
    public const string KEY = 'weight_volume';

    public function key(): string
    {
        return self::KEY;
    }

    public function pack(PackingRequest $request): PackingResult
    {
        $constraints = $request->constraints;
        $units = $this->expandUnits($request->items, $constraints);

        usort($units, static function (array $a, array $b): int {
            $byChargeable = $b['chargeableGrams'] <=> $a['chargeableGrams'];

            if ($byChargeable !== 0) {
                return $byChargeable;
            }

            $byPhysical = $b['physicalGrams'] <=> $a['physicalGrams'];

            return $byPhysical !== 0 ? $byPhysical : ($a['order'] <=> $b['order']);
        });

        /**
         * @var list<array{
         *     physicalGrams: int,
         *     volumeMm3: BigInteger,
         *     maxLongMm: int,
         *     maxMidMm: int,
         *     sumShortMm: int,
         *     hasAllDims: bool,
         *     units: non-empty-list<array{
         *         itemId: string,
         *         physicalGrams: int,
         *         volumetricGrams: int,
         *         volumeMm3: BigInteger,
         *         value: Money,
         *         sortedDims: array{0: int, 1: int, 2: int}|null
         *     }>
         * }> $bins
         */
        $bins = [];

        foreach ($units as $unit) {
            $placed = false;

            foreach ($bins as $index => $bin) {
                $candidate = self::tryAddUnit($bin, $unit, $constraints);

                if ($candidate !== null) {
                    $bins[$index] = $candidate;
                    $placed = true;

                    break;
                }
            }

            if (! $placed) {
                $dims = $unit['sortedDims'];
                $bins[] = [
                    'physicalGrams' => $constraints->tareWeightGrams + $unit['physicalGrams'],
                    'volumeMm3' => $unit['volumeMm3'],
                    'maxLongMm' => $dims === null ? 0 : $dims[2],
                    'maxMidMm' => $dims === null ? 0 : $dims[1],
                    'sumShortMm' => $dims === null ? 0 : $dims[0],
                    'hasAllDims' => $dims !== null,
                    'units' => [$unit],
                ];
            }
        }

        $packedParcels = [];

        foreach ($bins as $index => $bin) {
            $sequence = $index + 1;
            $currency = $bin['units'][0]['value']->currency;
            $parcelValue = Money::zero($currency);
            /** @var array<string, array{quantity: int, physicalGrams: int, volumetricGrams: int, value: Money}> $grouped */
            $grouped = [];

            foreach ($bin['units'] as $u) {
                $parcelValue = $parcelValue->plus($u['value']);
                $id = $u['itemId'];

                if (! isset($grouped[$id])) {
                    $grouped[$id] = [
                        'quantity' => 1,
                        'physicalGrams' => $u['physicalGrams'],
                        'volumetricGrams' => $u['volumetricGrams'],
                        'value' => $u['value'],
                    ];
                } else {
                    $grouped[$id]['quantity']++;
                    $grouped[$id]['physicalGrams'] += $u['physicalGrams'];
                    $grouped[$id]['volumetricGrams'] += $u['volumetricGrams'];
                    $grouped[$id]['value'] = $grouped[$id]['value']->plus($u['value']);
                }
            }

            $packedItems = [];

            foreach ($grouped as $itemId => $agg) {
                $packedItems[] = new PackedItem(
                    itemId: $itemId,
                    quantity: $agg['quantity'],
                    physicalWeightGrams: $agg['physicalGrams'],
                    volumetricWeightGrams: $agg['volumetricGrams'],
                    value: $agg['value'],
                );
            }

            $volumetricGrams = self::volumetricGrams($bin['volumeMm3'], $constraints->volumetricDivisor);
            $chargeableGrams = $constraints->useVolumetricWeight
                ? max($bin['physicalGrams'], $volumetricGrams)
                : $bin['physicalGrams'];

            $lengthMm = $bin['hasAllDims'] && $bin['maxLongMm'] > 0 ? $bin['maxLongMm'] : null;
            $widthMm = $bin['hasAllDims'] && $bin['maxMidMm'] > 0 ? $bin['maxMidMm'] : null;
            $heightMm = $bin['hasAllDims'] && $bin['sumShortMm'] > 0 ? $bin['sumShortMm'] : null;

            $packedParcels[] = new PackedParcel(
                sequence: $sequence,
                parcel: new Parcel(
                    weightGrams: $bin['physicalGrams'],
                    value: $parcelValue,
                    lengthMm: $lengthMm,
                    widthMm: $widthMm,
                    heightMm: $heightMm,
                ),
                physicalWeightGrams: $bin['physicalGrams'],
                volumetricWeightGrams: $volumetricGrams,
                chargeableWeightGrams: $chargeableGrams,
                items: $packedItems,
            );
        }

        return new PackingResult($packedParcels);
    }

    /**
     * @param  non-empty-list<PackableItem>  $items
     * @return list<array{
     *     order: int,
     *     itemId: string,
     *     physicalGrams: int,
     *     volumetricGrams: int,
     *     chargeableGrams: int,
     *     volumeMm3: BigInteger,
     *     value: Money,
     *     sortedDims: array{0: int, 1: int, 2: int}|null
     * }>
     */
    private function expandUnits(array $items, PackingConstraints $constraints): array
    {
        $boxLimits = ($constraints->maxLengthMm !== null || $constraints->maxWidthMm !== null || $constraints->maxHeightMm !== null)
            ? new PickupPointLimits(
                maxLengthMm: $constraints->maxLengthMm,
                maxWidthMm: $constraints->maxWidthMm,
                maxHeightMm: $constraints->maxHeightMm,
            )
            : null;

        $units = [];
        $order = 0;

        foreach ($items as $item) {
            $unitValues = MoneyAllocator::allocate($item->totalValue, array_fill(0, $item->quantity, 1));
            $volumeMm3 = $item->unitVolumeMm3();
            $volumetricGrams = $item->unitVolumetricWeightGrams($constraints->volumetricDivisor);
            $physicalWithTare = $constraints->tareWeightGrams + $item->unitWeightGrams;

            if ($physicalWithTare > $constraints->maxWeightGrams) {
                throw new UnpackableItem(
                    $item->itemId,
                    "single unit physical weight ({$physicalWithTare} g with tare) exceeds maxWeightGrams ({$constraints->maxWeightGrams} g)",
                );
            }

            if ($constraints->useVolumetricWeight && $volumetricGrams > $constraints->maxWeightGrams) {
                throw new UnpackableItem(
                    $item->itemId,
                    "single unit volumetric weight ({$volumetricGrams} g) exceeds maxWeightGrams ({$constraints->maxWeightGrams} g)",
                );
            }

            if ($constraints->maxVolumeMm3 !== null && $volumeMm3->compareTo($constraints->maxVolumeMm3) > 0) {
                throw new UnpackableItem(
                    $item->itemId,
                    "single unit volume ({$volumeMm3} mm³) exceeds maxVolumeMm3 ({$constraints->maxVolumeMm3} mm³)",
                );
            }

            $sortedDims = null;

            if ($item->lengthMm !== null && $item->widthMm !== null && $item->heightMm !== null) {
                $dims = [$item->lengthMm, $item->widthMm, $item->heightMm];
                sort($dims, SORT_NUMERIC);
                /** @var array{0: int, 1: int, 2: int} $dims */
                $sortedDims = $dims;

                if ($boxLimits !== null) {
                    $probe = new Parcel($item->unitWeightGrams, Money::zero($item->totalValue->currency), $dims[2], $dims[1], $dims[0]);
                    if (! $boxLimits->fitsParcel($probe, allowRotation: true)) {
                        throw new UnpackableItem(
                            $item->itemId,
                            'single unit dimensions exceed maximum box dimensions',
                        );
                    }
                }
            }

            $chargeableGrams = $constraints->useVolumetricWeight
                ? max($item->unitWeightGrams, $volumetricGrams)
                : $item->unitWeightGrams;

            for ($i = 0; $i < $item->quantity; $i++) {
                $units[] = [
                    'order' => $order++,
                    'itemId' => $item->itemId,
                    'physicalGrams' => $item->unitWeightGrams,
                    'volumetricGrams' => $volumetricGrams,
                    'chargeableGrams' => $chargeableGrams,
                    'volumeMm3' => $volumeMm3,
                    'value' => $unitValues[$i],
                    'sortedDims' => $sortedDims,
                ];
            }
        }

        return $units;
    }

    /**
     * @param  array{
     *     physicalGrams: int,
     *     volumeMm3: BigInteger,
     *     maxLongMm: int,
     *     maxMidMm: int,
     *     sumShortMm: int,
     *     hasAllDims: bool,
     *     units: non-empty-list<array{
     *         itemId: string,
     *         physicalGrams: int,
     *         volumetricGrams: int,
     *         volumeMm3: BigInteger,
     *         value: Money,
     *         sortedDims: array{0: int, 1: int, 2: int}|null
     *     }>
     * }  $bin
     * @param  array{
     *     itemId: string,
     *     physicalGrams: int,
     *     volumetricGrams: int,
     *     volumeMm3: BigInteger,
     *     value: Money,
     *     sortedDims: array{0: int, 1: int, 2: int}|null
     * }  $unit
     * @return array{
     *     physicalGrams: int,
     *     volumeMm3: BigInteger,
     *     maxLongMm: int,
     *     maxMidMm: int,
     *     sumShortMm: int,
     *     hasAllDims: bool,
     *     units: non-empty-list<array{
     *         itemId: string,
     *         physicalGrams: int,
     *         volumetricGrams: int,
     *         volumeMm3: BigInteger,
     *         value: Money,
     *         sortedDims: array{0: int, 1: int, 2: int}|null
     *     }>
     * }|null
     */
    private static function tryAddUnit(array $bin, array $unit, PackingConstraints $constraints): ?array
    {
        $nextPhysical = $bin['physicalGrams'] + $unit['physicalGrams'];

        if ($nextPhysical > $constraints->maxWeightGrams) {
            return null;
        }

        $nextVolume = $bin['volumeMm3']->plus($unit['volumeMm3']);

        if ($constraints->maxVolumeMm3 !== null && $nextVolume->compareTo($constraints->maxVolumeMm3) > 0) {
            return null;
        }

        if ($constraints->useVolumetricWeight && self::volumetricGrams($nextVolume, $constraints->volumetricDivisor) > $constraints->maxWeightGrams) {
            return null;
        }

        $dims = $unit['sortedDims'];
        $hasAllDims = $bin['hasAllDims'] && $dims !== null;
        $maxLong = $dims === null ? $bin['maxLongMm'] : max($bin['maxLongMm'], $dims[2]);
        $maxMid = $dims === null ? $bin['maxMidMm'] : max($bin['maxMidMm'], $dims[1]);
        $sumShort = $dims === null ? $bin['sumShortMm'] : $bin['sumShortMm'] + $dims[0];

        if ($hasAllDims && ($constraints->maxLengthMm !== null || $constraints->maxWidthMm !== null || $constraints->maxHeightMm !== null)) {
            $limits = new PickupPointLimits(
                maxLengthMm: $constraints->maxLengthMm,
                maxWidthMm: $constraints->maxWidthMm,
                maxHeightMm: $constraints->maxHeightMm,
            );
            $probe = new Parcel($nextPhysical, Money::zero($unit['value']->currency), $maxLong, $maxMid, $sumShort);

            if (! $limits->fitsParcel($probe, allowRotation: true)) {
                return null;
            }
        }

        $units = $bin['units'];
        $units[] = $unit;

        return [
            'physicalGrams' => $nextPhysical,
            'volumeMm3' => $nextVolume,
            'maxLongMm' => $maxLong,
            'maxMidMm' => $maxMid,
            'sumShortMm' => $sumShort,
            'hasAllDims' => $hasAllDims,
            'units' => $units,
        ];
    }

    private static function volumetricGrams(BigInteger $volumeMm3, int $divisor): int
    {
        if ($volumeMm3->isZero()) {
            return 0;
        }

        return BigDecimal::of($volumeMm3)
            ->dividedBy($divisor, 0, RoundingMode::Ceiling)
            ->toInt();
    }
}
