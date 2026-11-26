<?php

declare(strict_types=1);

namespace Commerz\Quotes\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Packman\Attributes\Api;

/**
 * One component line produced when {@see LineExpand} expands a parent bundle/kit line.
 */
#[Api(since: '0.1')]
final readonly class ExpandedComponent
{
    public function __construct(
        public SellableSnapshot|SellableRef $sellable,
        public int $quantityPerUnit = 1,
        public ?Money $fixedUnitPrice = null,
        public ?bool $taxIncluded = null,
        public ?string $lineId = null,
    ) {
        if ($quantityPerUnit < 1) {
            throw new InvalidCommand('quantityPerUnit', 'must be at least 1', 'quotes.invalid_transform');
        }

        if ($fixedUnitPrice !== null && $fixedUnitPrice->isNegative()) {
            throw new InvalidCommand('fixedUnitPrice', 'must not be negative', 'quotes.invalid_transform');
        }

        if ($lineId !== null && preg_match('/\A[A-Za-z0-9_.:\-]{1,64}\z/', $lineId) !== 1) {
            throw new InvalidCommand('lineId', "'{$lineId}' must match [A-Za-z0-9_.:-]{1,64}", 'quotes.invalid_transform');
        }
    }
}
