<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Events;

use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\Quotes\Data\QuoteRequestId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class QuoteRequestSubmitted
{
    public function __construct(
        public QuoteRequestId $quoteRequestId,
        public string $number,
        public BusinessUnitId $businessUnitId,
    ) {}
}
