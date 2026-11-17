<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Payments\Enums\PaymentStatus;
use Packman\Attributes\Api;

/** Payments, newest first. */
#[Api(since: '0.1')]
final readonly class ListPayments
{
    public function __construct(
        public ?OrderId $order = null,
        public ?PaymentStatus $status = null,
        public ?Cursor $after = null,
        public int $limit = 50,
    ) {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidCommand('limit', 'must be from 1 to 200', 'common.invalid_page_size');
        }
    }
}
