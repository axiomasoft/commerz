<?php

declare(strict_types=1);

namespace Commerz\Catalog\Listings\Data;

use Commerz\Catalog\Listings\Enums\ListingStatus;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ListingView
{
    public function __construct(
        public ProductId $product,
        public ChannelId $channel,
        public ListingStatus $status,
        public ?DateTimeImmutable $availableFrom,
        public ?DateTimeImmutable $availableUntil,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /** Published and `$at` falls inside the window. */
    public function isAvailableAt(DateTimeImmutable $at): bool
    {
        return $this->status === ListingStatus::Published
            && (! $this->availableFrom instanceof DateTimeImmutable || $at >= $this->availableFrom)
            && (! $this->availableUntil instanceof DateTimeImmutable || $at < $this->availableUntil);
    }
}
