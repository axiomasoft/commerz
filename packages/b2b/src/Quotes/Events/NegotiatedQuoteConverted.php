<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Events;

use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\Quotes\Data\NegotiatedQuoteId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class NegotiatedQuoteConverted
{
    public function __construct(
        public NegotiatedQuoteId $negotiatedQuoteId,
        public BusinessUnitId $businessUnitId,
        public string $orderRef,
    ) {}
}
