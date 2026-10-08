<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;

#[Api(since: '0.1')]
final readonly class BookShipment
{
    /**
     * @param  string  $reference  the shipping document, e.g. `fulfillment:01J…`; carriers dedupe by it
     * @param  array<string, scalar>  $meta
     */
    public function __construct(
        public string $reference,
        public string $carrier,
        public string $methodCode,
        public Address $destination,
        public Parcel $parcel,
        public array $meta = [],
    ) {
        if ($reference === '' || strlen($reference) > 191) {
            throw new InvalidCommand('reference', 'must be 1..191 bytes', 'delivery.invalid_booking');
        }

        if ($carrier === '' || $methodCode === '') {
            throw new InvalidCommand('carrier', 'carrier and method code must not be empty', 'delivery.invalid_booking');
        }
    }
}
