<?php

declare(strict_types=1);

namespace Commerz\Sales\Returns\Contracts;

use Commerz\Attributes\Api;
use Commerz\Errors\IdempotencyConflict;
use Commerz\Pagination\Page;
use Commerz\Sales\Common\Exceptions\LineNotFound;
use Commerz\Sales\Orders\Exceptions\OrderNotFound;
use Commerz\Sales\Returns\Data\CancelReturn;
use Commerz\Sales\Returns\Data\ListReturns;
use Commerz\Sales\Returns\Data\ReceiveReturn;
use Commerz\Sales\Returns\Data\RequestReturn;
use Commerz\Sales\Returns\Data\ReturnId;
use Commerz\Sales\Returns\Data\ReturnView;
use Commerz\Sales\Returns\Exceptions\RestockExceedsReceived;
use Commerz\Sales\Returns\Exceptions\ReturnExceedsFulfilled;
use Commerz\Sales\Returns\Exceptions\ReturnNotFound;
use Commerz\Sales\Returns\Exceptions\ReturnNotOpen;

/**
 * Returns of fulfilled goods (REF-01). A request only reserves the units against further returns; accepting the goods
 * counts them as returned, and only an explicit restock puts units back on hand.
 */
#[Api(since: '0.1')]
interface Returns
{
    /**
     * Opens a return of fulfilled units not yet returned and not held by another open return. A repeat with the same
     * idempotency key returns the same document.
     *
     * @throws OrderNotFound
     * @throws LineNotFound
     * @throws ReturnExceedsFulfilled more units than can still be returned, or the order is cancelled
     * @throws IdempotencyConflict
     */
    public function request(RequestReturn $command): ReturnView;

    /**
     * Accepts the goods: the units count as returned, and the physical ones listed in `restock` go back on hand under
     * the reference `return:{id}`. Receiving a received return changes nothing.
     *
     * @throws ReturnNotFound
     * @throws ReturnNotOpen the return was cancelled
     * @throws LineNotFound a restock line is not in the return
     * @throws RestockExceedsReceived
     */
    public function receive(ReceiveReturn $command): ReturnView;

    /**
     * Withdraws a requested return. Cancelling a cancelled return changes nothing.
     *
     * @throws ReturnNotFound
     * @throws ReturnNotOpen the return was received
     */
    public function cancel(CancelReturn $command): ReturnView;

    /** @throws ReturnNotFound */
    public function find(ReturnId $id): ReturnView;

    /** @return Page<ReturnView> */
    public function list(ListReturns $query): Page;
}
