<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;
use Commerz\Ports\Stock\Data\StockLines;
use Commerz\Sales\Fulfillments\Enums\FulfillmentOrderStatus;
use Commerz\Sales\Orders\Data\OrderId;
use Packman\Attributes\Api;

/** Fulfillment orders, newest first. */
#[Api(since: '0.1')]
final readonly class ListFulfillmentOrders
{
    public function __construct(
        public ?OrderId $order = null,
        public ?string $locationCode = null,
        public ?FulfillmentOrderStatus $status = null,
        public ?Cursor $after = null,
        public int $limit = 50,
    ) {
        StockLines::assertLocation($locationCode);

        if ($limit < 1 || $limit > 200) {
            throw new InvalidCommand('limit', 'must be from 1 to 200', 'common.invalid_page_size');
        }
    }
}
