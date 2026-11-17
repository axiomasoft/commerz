<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Pagination\Cursor;
use Commerz\Sales\Orders\Enums\FulfillmentState;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Stores\Data\ChannelId;
use Packman\Attributes\Api;

/** Orders, newest placed first. */
#[Api(since: '0.1')]
final readonly class ListOrders
{
    public function __construct(
        public ?ChannelId $channel = null,
        public ?CustomerRef $customer = null,
        public ?OrderState $state = null,
        public ?PaymentState $paymentState = null,
        public ?FulfillmentState $fulfillmentState = null,
        public ?Cursor $after = null,
        public int $limit = 50,
    ) {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidCommand('limit', 'must be from 1 to 200', 'common.invalid_page_size');
        }
    }
}
