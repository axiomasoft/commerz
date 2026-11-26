<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Internal;

use Commerz\Context\CommerceContext;
use Commerz\Identity\ActorRef;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Currency;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\PickupPointDestination;
use Commerz\Sales\Carts\Data\CartDeliveryGroup;
use Commerz\Sales\Carts\Enums\CartKind;
use Commerz\Sales\Carts\Enums\CartState;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;
use Packman\Attributes\Internal;

/** A stored cart with its lines in position order. */
#[Internal]
final readonly class CartRow
{
    /**
     * @param  list<string>  $groups
     * @param  list<string>  $codes
     * @param  list<CartLineRow>  $lines
     * @param  list<CartDeliveryGroup>  $deliveryGroups
     */
    public function __construct(
        public string $id,
        public CartKind $kind,
        public CartState $state,
        public ChannelId $channel,
        public Currency $currency,
        public string $locale,
        public ?CustomerRef $customer,
        public array $groups,
        public array $codes,
        public ?Address $shippingAddress,
        public ?Address $billingAddress,
        public ?string $deliveryMethod,
        public ?ActorRef $createdBy,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public array $lines,
        public ?PickupPointDestination $pickupPoint = null,
        public array $deliveryGroups = [],
    ) {}

    /** The context of this cart at `$at`: channel, currency and locale are the cart's own, for good (CTX-01). */
    public function context(DateTimeImmutable $at): CommerceContext
    {
        return new CommerceContext($this->channel, $this->currency, $this->locale, $this->customer, $this->groups, $at);
    }

    public function line(string $lineId): ?CartLineRow
    {
        return array_find($this->lines, static fn (CartLineRow $line): bool => $line->id === $lineId);
    }
}
