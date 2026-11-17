<?php

declare(strict_types=1);

namespace Commerz\Pricing\PriceLists\Enums;

use Packman\Attributes\Api;

/** Whether the amounts of a price list include tax; `AsChannel` leaves it to the channel of the quote context. */
#[Api(since: '0.1')]
enum TaxInclusion: string
{
    case Included = 'included';
    case Excluded = 'excluded';
    case AsChannel = 'as_channel';

    /** @internal column `tax_included`: null means the channel decides */
    public static function fromColumn(?bool $value): self
    {
        return match ($value) {
            true => self::Included,
            false => self::Excluded,
            null => self::AsChannel,
        };
    }

    /** @internal */
    public function toColumn(): ?bool
    {
        return match ($this) {
            self::Included => true,
            self::Excluded => false,
            self::AsChannel => null,
        };
    }
}
