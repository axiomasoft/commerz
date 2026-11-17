<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Pagination\Cursor;
use Commerz\Sales\Carts\Enums\CartKind;
use Commerz\Sales\Carts\Enums\CartState;
use Packman\Attributes\Api;

/** Carts, newest first. */
#[Api(since: '0.1')]
final readonly class ListCarts
{
    public function __construct(
        public ?CartKind $kind = null,
        public ?CartState $state = null,
        public ?CustomerRef $customer = null,
        public ?Cursor $after = null,
        public int $limit = 50,
    ) {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidCommand('limit', 'must be from 1 to 200', 'common.invalid_page_size');
        }
    }
}
