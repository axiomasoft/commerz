<?php

declare(strict_types=1);

namespace Commerz\Sales\Exchanges\Data;

use Commerz\Identity\ActorRef;
use Commerz\Ports\Stock\Data\StockLines;
use Commerz\Sales\Returns\Data\ReturnLineDisposition;
use Commerz\Sales\Returns\Enums\ReturnDisposition;
use Commerz\Sales\Returns\Enums\ReturnReason;
use Packman\Attributes\Api;

/**
 * Completes an open exchange: receives the inbound return with its RMA disposition (`Restock` or `DamagedWriteOff`)
 * and creates the outbound replacement fulfillment, consuming the exchange's stock reservation.
 */
#[Api(since: '0.1')]
final readonly class CompleteOrderExchange
{
    /**
     * @param  list<ReturnLineDisposition>  $returnDispositions
     */
    public function __construct(
        public ExchangeId $exchange,
        public ReturnDisposition $disposition = ReturnDisposition::Restock,
        public ?ReturnReason $reason = null,
        public ?string $returnLocationCode = null,
        public ?string $fulfillmentLocationCode = null,
        public array $returnDispositions = [],
        public ?ActorRef $actor = null,
    ) {
        StockLines::assertLocation($returnLocationCode);
        StockLines::assertLocation($fulfillmentLocationCode);
    }
}
