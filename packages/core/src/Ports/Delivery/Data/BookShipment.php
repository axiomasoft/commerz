<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class BookShipment
{
    /** @var non-empty-list<Parcel> */
    public array $parcels;

    /**
     * @param  string  $reference  the shipping document, e.g. `fulfillment:01J…`; carriers dedupe by it
     * @param  array<string, scalar>  $meta
     * @param  list<Parcel>  $parcels  individual parcels when split into 2+ boxes; defaults to `[$parcel]`
     */
    public function __construct(
        public string $reference,
        public string $carrier,
        public string $methodCode,
        public Address $destination,
        public Parcel $parcel,
        public array $meta = [],
        array $parcels = [],
        public ?PickupPointDestination $pickupPoint = null,
    ) {
        if ($reference === '' || strlen($reference) > 191) {
            throw new InvalidCommand('reference', 'must be 1..191 bytes', 'delivery.invalid_booking');
        }

        if ($carrier === '' || $methodCode === '') {
            throw new InvalidCommand('carrier', 'carrier and method code must not be empty', 'delivery.invalid_booking');
        }

        foreach ($parcels as $item) {
            if (! $item->value->currency->equals($parcel->value->currency)) {
                throw new InvalidCommand('parcels', 'every parcel must use the same currency as the shipment', 'delivery.invalid_booking');
            }
        }

        $this->parcels = $parcels === [] ? [$parcel] : $parcels;
    }

    /**
     * Builds a multi-parcel shipment command, deriving the aggregate weight and declared value from `$parcels`.
     *
     * @param  list<Parcel>  $parcels
     * @param  array<string, scalar>  $meta
     */
    public static function forParcels(
        string $reference,
        string $carrier,
        string $methodCode,
        Address $destination,
        array $parcels,
        array $meta = [],
        ?PickupPointDestination $pickupPoint = null,
    ): self {
        if ($parcels === []) {
            throw new InvalidCommand('parcels', 'must contain at least one parcel', 'delivery.invalid_booking');
        }

        $currency = $parcels[0]->value->currency;
        $totalWeight = 0;
        $totalValue = Money::zero($currency);

        foreach ($parcels as $item) {
            if (! $item->value->currency->equals($currency)) {
                throw new InvalidCommand('parcels', 'every parcel must use the same currency', 'delivery.invalid_booking');
            }

            $totalWeight += $item->weightGrams;
            $totalValue = $totalValue->plus($item->value);
        }

        $aggregate = count($parcels) === 1
            ? $parcels[0]
            : new Parcel($totalWeight, $totalValue);

        return new self(
            reference: $reference,
            carrier: $carrier,
            methodCode: $methodCode,
            destination: $destination,
            parcel: $aggregate,
            meta: $meta,
            parcels: $parcels,
            pickupPoint: $pickupPoint,
        );
    }
}
