<?php

declare(strict_types=1);

namespace Commerz\Ports\StoredValue\Data;

use Commerz\Money\Money;
use Packman\Attributes\Api;

/** Result of crediting a stored-value account for a refund. */
#[Api(since: '0.1')]
final readonly class StoredValueCredit
{
    public function __construct(
        public string $accountId,
        public Money $amount,
        public Money $balanceAfter,
        public string $journalId,
    ) {}
}
