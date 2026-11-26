<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Internal;

use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Ports\Sellables\Contracts\Sellables;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Exceptions\SellableUnavailable;
use Commerz\Quotes\Contracts\QuotePipeline;
use Commerz\Quotes\Data\DeliverySelection;
use Commerz\Quotes\Data\QuotedLine;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Quotes\Exceptions\QuoteFailed;
use Commerz\Sales\Carts\Exceptions\ShippingAddressRequired;
use DateTimeImmutable;
use Packman\Attributes\Internal;

/**
 * The only way a cart is quoted: `Carts::quote`, `Carts::deliveryOptions` and the placement of the order all build
 * their `QuoteInput` here, so the total the buyer saw is the total that is checked.
 */
#[Internal]
final readonly class CartQuoter
{
    public function __construct(private Sellables $sellables, private QuotePipeline $pipeline) {}

    /**
     * @param  bool  $withDelivery  false quotes the goods alone, to offer delivery methods for them
     *
     * @throws ShippingAddressRequired a method is selected and there is no shipping address
     * @throws SellableUnavailable
     * @throws QuoteFailed a stage failed, or a selected method that ships was priced by none
     */
    public function quoteCart(CartRow $cart, DateTimeImmutable $at, bool $withDelivery = true): QuotedCart
    {
        $context = $cart->context($at);
        $snapshots = $this->sellables->resolveMany(
            array_map(static fn (CartLineRow $line): SellableRef => $line->sellable, $cart->lines),
            $context,
        );

        $lines = array_map(
            static fn (CartLineRow $line): QuoteLineInput => new QuoteLineInput($line->id, $snapshots[(string) $line->sellable], $line->quantity),
            $cart->lines,
        );

        $delivery = null;

        if ($withDelivery && $cart->deliveryMethod !== null) {
            $address = $cart->pickupPoint?->toDeliveryAddress() ?? $cart->shippingAddress ?? throw new ShippingAddressRequired;
            // The delivery stage replaces the declared value with the value of the goods after discounts.
            $delivery = new DeliverySelection(
                $cart->deliveryMethod,
                $address,
                new Parcel(self::shippingWeight($cart->lines, $snapshots), Money::zero($cart->currency)),
            );
        } elseif ($withDelivery && count($cart->deliveryGroups) === 1) {
            $group = $cart->deliveryGroups[0];
            $groupLines = array_values(array_filter(
                $cart->lines,
                static fn (CartLineRow $line): bool => in_array($line->id, $group->lineIds, true),
            ));
            $address = $group->pickupPoint?->toDeliveryAddress()
                ?? $group->shippingAddress
                ?? $cart->pickupPoint?->toDeliveryAddress()
                ?? $cart->shippingAddress
                ?? throw new ShippingAddressRequired;

            $delivery = new DeliverySelection(
                $group->methodCode,
                $address,
                new Parcel(self::shippingWeight($groupLines, $snapshots), Money::zero($cart->currency)),
            );
        }

        $quote = $this->pipeline->run(new QuoteInput($context, $lines, $cart->codes, $delivery));

        foreach ($quote->lines as $quotedLine) {
            $snapshots[(string) $quotedLine->sellable->ref] = $quotedLine->sellable;
        }

        // No stage prices delivery without the shipping package; a selected method must not be silently dropped from the total.
        if ($delivery !== null && $quote->delivery === null && self::quotedShippingLines($quote->lines) !== []) {
            throw new QuoteFailed('delivery', 'method_unpriced', "no stage priced the selected delivery method '{$delivery->methodCode}'");
        }

        return new QuotedCart($quote, $snapshots);
    }

    /**
     * Grams of the lines that ship: weight × quantity, an unknown weight counting as 0.
     *
     * @param  list<CartLineRow>  $lines
     * @param  array<string, SellableSnapshot>  $snapshots
     */
    public static function shippingWeight(array $lines, array $snapshots): int
    {
        return array_sum(array_map(
            static fn (CartLineRow $line): int => ($snapshots[(string) $line->sellable]->weightGrams ?? 0) * $line->quantity,
            self::shippingLines($lines, $snapshots),
        ));
    }

    /**
     * Grams of the post-transform quoted lines that ship: weight × quantity, an unknown weight counting as 0.
     *
     * @param  list<QuotedLine>  $lines
     */
    public static function quotedShippingWeight(array $lines): int
    {
        return array_sum(array_map(
            static fn (QuotedLine $line): int => ($line->sellable->weightGrams ?? 0) * $line->quantity,
            self::quotedShippingLines($lines),
        ));
    }

    /**
     * @param  list<CartLineRow>  $lines
     * @param  array<string, SellableSnapshot>  $snapshots
     * @return list<CartLineRow> the lines that ship
     */
    private static function shippingLines(array $lines, array $snapshots): array
    {
        return array_values(array_filter(
            $lines,
            static fn (CartLineRow $line): bool => $snapshots[(string) $line->sellable]->requiresShipping,
        ));
    }

    /**
     * @param  list<QuotedLine>  $lines
     * @return list<QuotedLine>
     */
    private static function quotedShippingLines(array $lines): array
    {
        return array_values(array_filter(
            $lines,
            static fn (QuotedLine $line): bool => $line->sellable->requiresShipping,
        ));
    }
}
