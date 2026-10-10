<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Data;

use Commerz\Attributes\Api;
use Commerz\Identity\ActorRef;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Currency;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Sales\Carts\Enums\CartKind;
use Commerz\Sales\Carts\Enums\CartState;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;

#[Api(since: '0.1')]
final readonly class CartView
{
    /**
     * @param  list<string>  $customerGroups
     * @param  list<CartLineView>  $lines  in the order they were added
     * @param  list<string>  $codes
     * @param  int  $version  grows with every change, so a caller can tell a cart changed under it
     */
    public function __construct(
        public CartId $id,
        public CartKind $kind,
        public CartState $state,
        public ChannelId $channel,
        public Currency $currency,
        public string $locale,
        public ?CustomerRef $customer,
        public array $customerGroups,
        public array $lines,
        public array $codes,
        public ?Address $shippingAddress,
        public ?Address $billingAddress,
        public ?string $deliveryMethod,
        public int $version,
        public ?ActorRef $createdBy,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
