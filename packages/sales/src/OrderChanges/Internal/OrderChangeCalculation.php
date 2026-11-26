<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Internal;

use Commerz\Identity\SellableRef;
use Commerz\Sales\OrderChanges\Data\OrderChangePreview;
use Packman\Attributes\Internal;

/** Internal dry-run calculation output combining the public preview with physical stock deltas. */
#[Internal]
final readonly class OrderChangeCalculation
{
    /**
     * @param  array<string, int>  $stockDeltas  sellable string => signed net physical quantity delta (after - before)
     * @param  array<string, SellableRef>  $stockSellables  sellable string => SellableRef
     */
    public function __construct(
        public OrderChangePreview $preview,
        public array $stockDeltas,
        public array $stockSellables,
    ) {}
}
