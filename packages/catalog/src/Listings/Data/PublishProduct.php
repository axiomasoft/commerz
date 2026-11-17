<?php

declare(strict_types=1);

namespace Commerz\Catalog\Listings\Data;

use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Errors\InvalidCommand;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Publishes a product in an active channel, optionally for a window `[availableFrom, availableUntil)`; a null bound is
 * open. Publishing again replaces the window.
 */
#[Api(since: '0.1')]
final readonly class PublishProduct
{
    public function __construct(
        public ProductId $product,
        public ChannelId $channel,
        public ?DateTimeImmutable $availableFrom = null,
        public ?DateTimeImmutable $availableUntil = null,
    ) {
        if ($availableFrom instanceof DateTimeImmutable && $availableUntil instanceof DateTimeImmutable && $availableUntil <= $availableFrom) {
            throw new InvalidCommand('availableUntil', 'must be after availableFrom', 'catalog.invalid_window');
        }
    }
}
