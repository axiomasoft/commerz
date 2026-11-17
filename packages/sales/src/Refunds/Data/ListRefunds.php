<?php

declare(strict_types=1);

namespace Commerz\Sales\Refunds\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Refunds\Enums\RefundStatus;
use Packman\Attributes\Api;

/** Refunds, newest first. */
#[Api(since: '0.1')]
final readonly class ListRefunds
{
    public function __construct(
        public ?OrderId $order = null,
        public ?RefundStatus $status = null,
        public ?Cursor $after = null,
        public int $limit = 50,
    ) {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidCommand('limit', 'must be from 1 to 200', 'common.invalid_page_size');
        }
    }
}
