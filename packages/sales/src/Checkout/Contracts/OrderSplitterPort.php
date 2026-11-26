<?php

declare(strict_types=1);

namespace Commerz\Sales\Checkout\Contracts;

use Commerz\Quotes\Data\QuoteResult;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Extension seam for splitting a master checkout quote into seller sub-order line-id groups.
 * The default implementation keeps all lines in a single order.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface OrderSplitterPort
{
    /**
     * Partitions the quoted lines into one or more non-empty lists of line ids.
     *
     * @return non-empty-list<non-empty-list<string>>
     */
    public function split(QuoteResult $quote): array;
}
