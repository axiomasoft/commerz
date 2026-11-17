<?php

declare(strict_types=1);

namespace Commerz\Shipping\Options\Internal;

use Commerz\Ports\Delivery\Contracts\DeliveryOptions;
use Commerz\Ports\Delivery\Data\DeliveryOption;
use Commerz\Ports\Delivery\Data\DeliveryRequest;
use Commerz\Shipping\Options\Contracts\ShippingOptions;
use Commerz\Shipping\Options\Data\MethodVerdict;
use Commerz\Shipping\Options\Data\OptionsExplanation;
use Packman\Attributes\Internal;

/** The options and the `DeliveryOptions` port of the kernel, both answered by `MethodPricer`. */
#[Internal]
final readonly class DatabaseShippingOptions implements DeliveryOptions, ShippingOptions
{
    public function __construct(private MethodPricer $pricer) {}

    public function options(DeliveryRequest $request): array
    {
        return $this->list($request);
    }

    public function list(DeliveryRequest $request): array
    {
        $available = array_values(array_filter($this->pricer->price($request), static fn (PricedMethod $method): bool => $method->isAvailable()));

        usort($available, static function (PricedMethod $a, PricedMethod $b): int {
            $byPositionAndPrice = [$a->position, $a->price?->amountMinor] <=> [$b->position, $b->price?->amountMinor];

            return $byPositionAndPrice !== 0 ? $byPositionAndPrice : strcmp($a->methodCode, $b->methodCode);
        });

        return array_map(static fn (PricedMethod $method): DeliveryOption => $method->option($request->context->locale), $available);
    }

    public function explain(DeliveryRequest $request): OptionsExplanation
    {
        return new OptionsExplanation(array_map(static fn (PricedMethod $method): MethodVerdict => $method->verdict(), $this->pricer->price($request)));
    }
}
