<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Contracts;

use Commerz\B2b\Quotes\Data\PlacedB2bOrder;
use Commerz\B2b\Quotes\Data\PlaceLockedQuoteOrder;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Host or Sales-integration port that places an Order from an approved NegotiatedQuote while locked prices are active
 * in the QuotePipeline.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface OrderPlacementPort
{
    public function placeLockedQuote(PlaceLockedQuoteOrder $request): PlacedB2bOrder;
}
