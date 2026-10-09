<?php

declare(strict_types=1);

namespace Commerz\Shipping\Options\Contracts;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Ports\Delivery\Data\DeliveryOption;
use Commerz\Ports\Delivery\Data\DeliveryRequest;
use Commerz\Shipping\Options\Data\OptionsExplanation;

/**
 * Priced delivery methods for a destination and parcel, and why the others are not offered. The price is the one the
 * delivery stage of a quote charges for the same input. `$request->parcel->value` is the value of the goods after
 * discounts, in the context's currency: it decides free delivery above a threshold. Reads only; a failing rate plugin
 * makes its method unavailable (`invalid_rate_result`), never the whole call.
 */
#[Api(since: '0.1')]
interface ShippingOptions
{
    /**
     * The available methods, by position, then price, then code; the same list as the `DeliveryOptions` port.
     *
     * @return list<DeliveryOption> in the context's currency; empty when nothing delivers there
     *
     * @throws InvalidCommand shipping.currency_mismatch when the parcel value is not in the context's currency
     */
    public function list(DeliveryRequest $request): array;

    /**
     * The verdict of every method that is not archived, by code.
     *
     * @throws InvalidCommand shipping.currency_mismatch when the parcel value is not in the context's currency
     */
    public function explain(DeliveryRequest $request): OptionsExplanation;
}
