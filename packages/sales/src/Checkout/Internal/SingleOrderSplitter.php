<?php

declare(strict_types=1);

namespace Commerz\Sales\Checkout\Internal;

use Commerz\Quotes\Data\QuotedLine;
use Commerz\Quotes\Data\QuoteResult;
use Commerz\Sales\Checkout\Contracts\OrderSplitterPort;
use Override;
use Packman\Attributes\Internal;

/**
 * Default no-op order splitter: keeps every line of the quote in a single order.
 */
#[Internal]
final readonly class SingleOrderSplitter implements OrderSplitterPort
{
    /** @return non-empty-list<non-empty-list<string>> */
    #[Override]
    public function split(QuoteResult $quote): array
    {
        if ($quote->lines === []) {
            return [['']];
        }

        return [array_map(static fn (QuotedLine $line): string => $line->lineId, $quote->lines)];
    }
}
