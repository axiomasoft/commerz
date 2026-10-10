<?php

declare(strict_types=1);

namespace Commerz\Sales\Checkout\Internal;

use Commerz\Ports\Customers\Data\CustomerProfile;
use Commerz\Ports\Promotions\Data\AppliedPromotion;
use Commerz\Quotes\Data\QuoteResult;
use Commerz\Sales\Common\Internal\CartRow;
use Commerz\Sales\Numbering\Contracts\OrderNumberGenerator;
use Commerz\Stores\Data\ChannelView;
use Commerz\Stores\Data\StoreView;
use DateTimeImmutable;

/** What the placement read before its transaction: the cart as quoted and everything the snapshot takes from ports. */
final readonly class Placement
{
    /** @param  list<AppliedPromotion>  $applied */
    public function __construct(
        public CartRow $cart,
        public QuoteResult $quote,
        public array $applied,
        public ChannelView $channel,
        public StoreView $store,
        public ?CustomerProfile $customer,
        public OrderNumberGenerator $generator,
        public DateTimeImmutable $at,
    ) {}
}
