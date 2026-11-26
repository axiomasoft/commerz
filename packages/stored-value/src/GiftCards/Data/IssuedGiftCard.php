<?php

declare(strict_types=1);

namespace Commerz\StoredValue\GiftCards\Data;

use Packman\Attributes\Api;

/** Result of issuing a gift card: carries the one-time plaintext code alongside the card projection. */
#[Api(since: '0.1')]
final readonly class IssuedGiftCard
{
    public function __construct(
        public GiftCardView $giftCard,
        public string $plainCode,
    ) {}
}
