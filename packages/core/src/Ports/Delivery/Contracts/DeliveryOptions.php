<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Contracts;

use Commerz\Ports\Delivery\Data\DeliveryOption;
use Commerz\Ports\Delivery\Data\DeliveryRequest;
use Packman\Attributes\Api;

/** Priced delivery methods for a destination and parcel. Reads only; never calls a carrier inside a transaction. */
#[Api(since: '0.1')]
interface DeliveryOptions
{
    /** @return list<DeliveryOption> in the context's currency; empty when nothing delivers there */
    public function options(DeliveryRequest $request): array;
}
