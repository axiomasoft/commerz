<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Internal;

use Commerz\B2b\Quotes\Contracts\OrderPlacementPort;
use Commerz\B2b\Quotes\Data\PlacedB2bOrder;
use Commerz\B2b\Quotes\Data\PlaceLockedQuoteOrder;
use Commerz\Identity\Uuid7;
use Override;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DefaultOrderPlacementPort implements OrderPlacementPort
{
    #[Override]
    public function placeLockedQuote(PlaceLockedQuoteOrder $request): PlacedB2bOrder
    {
        $orderId = Uuid7::generate();
        $short = strtoupper(substr(str_replace('-', '', $orderId), -8));

        return new PlacedB2bOrder(
            orderRef: 'order:'.$orderId,
            orderNumber: 'B2B-'.$short,
            total: $request->lockedTotals->total,
            paymentDueAt: $request->paymentSchedule?->finalDueAt,
        );
    }
}
