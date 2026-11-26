<?php

declare(strict_types=1);

namespace Commerz\Sales\Exchanges\Data;

use Commerz\Identity\ActorRef;
use Commerz\Ports\Stock\Data\StockLines;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class FulfillOrderClaim
{
    public function __construct(
        public ClaimId $claim,
        public ?string $locationCode = null,
        public ?ActorRef $actor = null,
    ) {
        StockLines::assertLocation($locationCode);
    }
}
