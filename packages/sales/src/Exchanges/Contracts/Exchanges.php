<?php

declare(strict_types=1);

namespace Commerz\Sales\Exchanges\Contracts;

use Commerz\Errors\IdempotencyConflict;
use Commerz\Pagination\Page;
use Commerz\Ports\Sellables\Exceptions\SellableUnavailable;
use Commerz\Ports\Stock\Exceptions\InsufficientStock;
use Commerz\Quotes\Exceptions\QuoteFailed;
use Commerz\Sales\Carts\Exceptions\SellableNotPurchasable;
use Commerz\Sales\Common\Exceptions\LineNotFound;
use Commerz\Sales\Exchanges\Data\CancelOrderClaim;
use Commerz\Sales\Exchanges\Data\CancelOrderExchange;
use Commerz\Sales\Exchanges\Data\ClaimId;
use Commerz\Sales\Exchanges\Data\CompleteOrderExchange;
use Commerz\Sales\Exchanges\Data\CreateOrderClaim;
use Commerz\Sales\Exchanges\Data\CreateOrderExchange;
use Commerz\Sales\Exchanges\Data\ExchangeId;
use Commerz\Sales\Exchanges\Data\FulfillOrderClaim;
use Commerz\Sales\Exchanges\Data\ListOrderClaims;
use Commerz\Sales\Exchanges\Data\ListOrderExchanges;
use Commerz\Sales\Exchanges\Data\OrderClaim;
use Commerz\Sales\Exchanges\Data\OrderExchange;
use Commerz\Sales\Exchanges\Exceptions\ClaimNotFound;
use Commerz\Sales\Exchanges\Exceptions\ClaimNotOpen;
use Commerz\Sales\Exchanges\Exceptions\ExchangeNotFound;
use Commerz\Sales\Exchanges\Exceptions\ExchangeNotOpen;
use Commerz\Sales\Orders\Exceptions\OrderNotFound;
use Commerz\Sales\Returns\Exceptions\RestockExceedsReceived;
use Commerz\Sales\Returns\Exceptions\ReturnExceedsFulfilled;
use Packman\Attributes\Api;

/**
 * Post-purchase RMA exchanges (`OrderExchange`: inbound return + outbound replacement fulfillment with net delta)
 * and claims (`OrderClaim`: free replacement or reshipment with zero invoice).
 */
#[Api(since: '0.1')]
interface Exchanges
{
    /**
     * @throws OrderNotFound|LineNotFound|ReturnExceedsFulfilled
     * @throws SellableUnavailable|SellableNotPurchasable|QuoteFailed|InsufficientStock|IdempotencyConflict
     */
    public function createExchange(CreateOrderExchange $command): OrderExchange;

    /**
     * @throws ExchangeNotFound|ExchangeNotOpen|LineNotFound|RestockExceedsReceived|InsufficientStock
     */
    public function completeExchange(CompleteOrderExchange $command): OrderExchange;

    /**
     * @throws ExchangeNotFound|ExchangeNotOpen
     */
    public function cancelExchange(CancelOrderExchange $command): OrderExchange;

    /**
     * @throws ExchangeNotFound
     */
    public function findExchange(ExchangeId $id): OrderExchange;

    /**
     * @return Page<OrderExchange>
     */
    public function listExchanges(ListOrderExchanges $query): Page;

    /**
     * @throws OrderNotFound|LineNotFound|ReturnExceedsFulfilled
     * @throws SellableUnavailable|SellableNotPurchasable|InsufficientStock|IdempotencyConflict
     */
    public function createClaim(CreateOrderClaim $command): OrderClaim;

    /**
     * @throws ClaimNotFound|ClaimNotOpen|InsufficientStock
     */
    public function fulfillClaim(FulfillOrderClaim $command): OrderClaim;

    /**
     * @throws ClaimNotFound|ClaimNotOpen
     */
    public function cancelClaim(CancelOrderClaim $command): OrderClaim;

    /**
     * @throws ClaimNotFound
     */
    public function findClaim(ClaimId $id): OrderClaim;

    /**
     * @return Page<OrderClaim>
     */
    public function listClaims(ListOrderClaims $query): Page;
}
