<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Data;

use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Packman\Attributes\Api;

/** Asks why a sellable costs what it costs in a context; the answer of `Prices::explain` follows the resolver's own path. */
#[Api(since: '0.1')]
final readonly class ExplainPrice
{
    /** @param  Money|null  $fallback  the sellable's own base price, as `ResolvePrice::$fallback` */
    public function __construct(
        public SellableRef $sellable,
        public int $quantity,
        public CommerceContext $context,
        public ?Money $fallback = null,
    ) {
        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'pricing.invalid_quantity');
        }
    }
}
