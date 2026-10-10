<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Data;

use Commerz\Attributes\Api;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Fulfillments\Enums\FulfillmentStatus;
use Commerz\Sales\Orders\Data\OrderId;
use DateTimeImmutable;

/** One fulfillment document of an order. */
#[Api(since: '0.1')]
final readonly class FulfillmentView
{
    /**
     * @param  list<LineQuantity>  $lines  by line id
     * @param  string|null  $labelRef  where the carrier label can be fetched, once booked
     */
    public function __construct(
        public FulfillmentId $id,
        public OrderId $order,
        public FulfillmentStatus $status,
        public array $lines,
        public ?string $locationCode,
        public ?string $carrier,
        public ?string $methodCode,
        public ?string $tracking,
        public ?string $labelRef,
        public ?DateTimeImmutable $shippedAt,
        public ?DateTimeImmutable $deliveredAt,
        public DateTimeImmutable $createdAt,
    ) {}
}
