<?php

declare(strict_types=1);

namespace Commerz\Sales\Numbering\Data;

use Commerz\Attributes\Api;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\StoreView;
use DateTimeImmutable;

/** What a generator may use to number the order being placed. */
#[Api(since: '0.1')]
final readonly class OrderNumbering
{
    public function __construct(
        public StoreView $store,
        public ChannelId $channel,
        public OrderId $orderId,
        public DateTimeImmutable $at,
    ) {}
}
