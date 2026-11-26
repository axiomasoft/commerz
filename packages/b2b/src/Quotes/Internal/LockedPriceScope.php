<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Internal;

use Commerz\Money\Money;
use Packman\Attributes\Internal;

/**
 * Request-local scope carrying locked B2B unit prices during StagedQuote calculation and NegotiatedQuote -> Order
 * conversion so live catalog price mutations never alter negotiated totals.
 */
#[Internal]
final class LockedPriceScope
{
    /** @var array<string, Money> */
    private array $byLineId = [];

    /** @var array<string, Money> */
    private array $bySellableRef = [];

    private string $priceSource = 'b2b.negotiated_quote';

    private bool $active = false;

    /**
     * @template T
     *
     * @param  array<string, Money>  $byLineId
     * @param  array<string, Money>  $bySellableRef
     * @param  callable(): T  $callback
     * @return T
     */
    public function runWithLocks(array $byLineId, array $bySellableRef, string $priceSource, callable $callback): mixed
    {
        $prevLine = $this->byLineId;
        $prevSellable = $this->bySellableRef;
        $prevSource = $this->priceSource;
        $prevActive = $this->active;

        $this->byLineId = $byLineId;
        $this->bySellableRef = $bySellableRef;
        $this->priceSource = $priceSource;
        $this->active = true;

        try {
            return $callback();
        } finally {
            $this->byLineId = $prevLine;
            $this->bySellableRef = $prevSellable;
            $this->priceSource = $prevSource;
            $this->active = $prevActive;
        }
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function priceSource(): string
    {
        return $this->priceSource;
    }

    public function resolveUnitPrice(string $lineId, string $sellableRef): ?Money
    {
        if (! $this->active) {
            return null;
        }

        return $this->byLineId[$lineId] ?? $this->bySellableRef[$sellableRef] ?? null;
    }
}
