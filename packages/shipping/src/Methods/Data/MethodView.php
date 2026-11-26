<?php

declare(strict_types=1);

namespace Commerz\Shipping\Methods\Data;

use Commerz\Measures\LocalizedText;
use Commerz\Ports\Delivery\Enums\DeliveryMode;
use Commerz\Shipping\Methods\Enums\MethodStatus;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class MethodView
{
    /**
     * @param  list<ChannelId>  $channels  by channel id
     * @param  list<RateView>  $rates  by zone code, then currency
     */
    public function __construct(
        public MethodId $id,
        public string $code,
        public LocalizedText $name,
        public string $carrier,
        public MethodStatus $status,
        public int $position,
        public array $channels,
        public array $rates,
        public DateTimeImmutable $updatedAt,
        public DeliveryMode $mode = DeliveryMode::Shipping,
    ) {}
}
