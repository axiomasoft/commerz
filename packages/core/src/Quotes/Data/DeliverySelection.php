<?php

declare(strict_types=1);

namespace Commerz\Quotes\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\Parcel;
use Packman\Attributes\Api;

/** The delivery method the buyer chose; the delivery stage prices it. */
#[Api(since: '0.1')]
final readonly class DeliverySelection
{
    public function __construct(public string $methodCode, public Address $address, public Parcel $parcel)
    {
        if ($methodCode === '') {
            throw new InvalidCommand('methodCode', 'must not be empty', 'quotes.invalid_delivery');
        }
    }
}
