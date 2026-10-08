<?php

declare(strict_types=1);

namespace Commerz\Stores\Data;

use Commerz\Attributes\Api;

/** Changes the given fields; a null field stays as it is. The code and default currency never change. */
#[Api(since: '0.1')]
final readonly class UpdateStore
{
    public function __construct(
        public StoreId $store,
        public ?string $name = null,
        public ?string $orderNumberPrefix = null,
    ) {
        if ($name !== null) {
            Codes::name($name);
        }

        if ($orderNumberPrefix !== null) {
            Codes::orderNumberPrefix($orderNumberPrefix);
        }
    }
}
