<?php

declare(strict_types=1);

namespace Commerz\Sales\Exchanges\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;
use Commerz\Sales\Exchanges\Enums\ClaimStatus;
use Commerz\Sales\Orders\Data\OrderId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ListOrderClaims
{
    public function __construct(
        public ?OrderId $order = null,
        public ?ClaimStatus $status = null,
        public ?Cursor $after = null,
        public int $limit = 50,
    ) {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidCommand('limit', 'must be between 1 and 500', 'sales.invalid_limit');
        }
    }
}
