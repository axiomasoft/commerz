<?php

declare(strict_types=1);

namespace Commerz\Quotes\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Quotes\Contracts\CartTransformOperation;
use Packman\Attributes\Api;

/**
 * Updates an existing input line's price, display title, sellable, or quantity before pricing.
 */
#[Api(since: '0.1')]
final readonly class LineUpdate implements CartTransformOperation
{
    public function __construct(
        public string $lineId,
        public ?Money $fixedUnitPrice = null,
        public ?string $title = null,
        public SellableSnapshot|SellableRef|null $sellable = null,
        public ?int $quantity = null,
        public ?bool $taxIncluded = null,
    ) {
        if (preg_match('/\A[A-Za-z0-9_.:\-]{1,64}\z/', $lineId) !== 1) {
            throw new InvalidCommand('lineId', "'{$lineId}' must match [A-Za-z0-9_.:-]{1,64}", 'quotes.invalid_transform');
        }

        if ($fixedUnitPrice === null && $title === null && $sellable === null && $quantity === null && $taxIncluded === null) {
            throw new InvalidCommand('lineUpdate', 'must specify at least one attribute to update', 'quotes.invalid_transform');
        }

        if ($fixedUnitPrice !== null && $fixedUnitPrice->isNegative()) {
            throw new InvalidCommand('fixedUnitPrice', 'must not be negative', 'quotes.invalid_transform');
        }

        if ($title !== null && trim($title) === '') {
            throw new InvalidCommand('title', 'must not be blank', 'quotes.invalid_transform');
        }

        if ($quantity !== null && $quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'quotes.invalid_transform');
        }
    }
}
