<?php

declare(strict_types=1);

namespace Commerz\Quotes\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\RoundingMode;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class QuoteLineInput
{
    /** @param  string  $lineId  the caller's line id (cart or order line), unique in the quote: [A-Za-z0-9_.:-]{1,64} */
    public function __construct(public string $lineId, public SellableSnapshot $sellable, public int $quantity)
    {
        if (preg_match('/\A[A-Za-z0-9_.:\-]{1,64}\z/', $lineId) !== 1) {
            throw new InvalidCommand('lineId', "'{$lineId}' must match [A-Za-z0-9_.:-]{1,64}", 'quotes.invalid_line');
        }

        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'quotes.invalid_line');
        }

        $sellable->quantityStepPolicy?->assertValid($quantity);
    }

    /** Builds a line input whose quantity is normalized to the sellable's QuantityStepPolicy, if present. */
    public static function normalized(
        string $lineId,
        SellableSnapshot $sellable,
        int $quantity,
        RoundingMode $mode = RoundingMode::HalfUp,
    ): self {
        $resolvedQuantity = $sellable->quantityStepPolicy !== null
            ? $sellable->quantityStepPolicy->normalize($quantity, $mode)
            : $quantity;

        return new self($lineId, $sellable, $resolvedQuantity);
    }
}
