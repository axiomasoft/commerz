<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Internal;

use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Quotes\Data\QuoteResult;
use Packman\Attributes\Internal;

/** The quote of a cart and the sellables it was made from. */
#[Internal]
final readonly class QuotedCart
{
    /** @param  array<string, SellableSnapshot>  $snapshots  keyed by `(string) SellableRef` */
    public function __construct(public QuoteResult $quote, public array $snapshots) {}
}
