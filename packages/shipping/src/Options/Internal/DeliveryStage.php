<?php

declare(strict_types=1);

namespace Commerz\Shipping\Options\Internal;

use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\DeliveryRequest;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Data\DeliveryCharge;
use Commerz\Quotes\Data\QuoteLine;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Data\Rejection;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;
use Commerz\Quotes\Exceptions\QuoteFailed;
use Commerz\Shipping\Options\Exceptions\DeliveryUnavailable;
use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Internal;

/**
 * Prices the selected delivery method with `MethodPricer`, the price the options show (SHP-01). The parcel keeps the
 * caller's weight and sizes, but its value is always the goods after line and order discounts, so a free-delivery
 * threshold and value-based rates see the discounts. A selected method that cannot deliver fails the quote with
 * `method_unavailable` (SHP-02): a quote never silently drops the delivery. Pure: it reads, writes nothing, takes the
 * time from the context. The pricer is resolved per run, so a binding changed after the registry froze is still used.
 */
#[Internal]
final readonly class DeliveryStage implements QuoteStage
{
    public function __construct(private Container $container) {}

    public function key(): string
    {
        return QuoteAnchor::Delivery->value;
    }

    public function anchor(): QuoteAnchor
    {
        return QuoteAnchor::Delivery;
    }

    public function position(): StagePosition
    {
        return StagePosition::At;
    }

    public function apply(QuoteState $state): QuoteState
    {
        $selection = $state->input->delivery;

        if ($selection === null) {
            return $state;
        }

        $shipped = array_values(array_filter($state->lines, static fn (QuoteLine $line): bool => $line->sellable->requiresShipping));

        if ($shipped === []) {
            return $state->withRejection(new Rejection($this->key(), 'delivery_not_required', 'no line of the quote requires shipping'));
        }

        $request = new DeliveryRequest(
            $state->context(),
            $selection->address,
            new Parcel($selection->parcel->weightGrams, self::goods($state), $selection->parcel->lengthMm, $selection->parcel->widthMm, $selection->parcel->heightMm),
            array_map(static fn (QuoteLine $line): SellableRef => $line->sellable->ref, $shipped),
        );

        $method = $this->container->make(MethodPricer::class)->price($request, $selection->methodCode)[0]
            ?? PricedMethod::notFound($selection->methodCode);

        if (! $method->isAvailable() || $method->price === null || $method->name === null || $method->carrier === null) {
            throw new QuoteFailed(
                $this->key(),
                'method_unavailable',
                "shipping method '{$method->methodCode}' cannot deliver this quote ({$method->verdict}): {$method->message}",
                new DeliveryUnavailable($method->methodCode, $method->verdict, $method->message),
            );
        }

        return $state->withDelivery(new DeliveryCharge(
            $method->methodCode,
            $method->name->resolve($state->context()->locale),
            $method->carrier,
            $method->price,
            $method->taxIncluded,
        ));
    }

    /** The goods after line discounts and order-level adjustments, never below zero. */
    private static function goods(QuoteState $state): Money
    {
        $goods = $state->itemsNet();

        foreach ($state->adjustments as $adjustment) {
            if ($adjustment->target === AdjustmentTarget::Order) {
                $goods = $goods->plus($adjustment->amount);
            }
        }

        return $goods->isNegative() ? Money::zero($state->currency()) : $goods;
    }
}
