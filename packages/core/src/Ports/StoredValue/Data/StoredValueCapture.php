<?php

declare(strict_types=1);

namespace Commerz\Ports\StoredValue\Data;

use Commerz\Money\Money;
use Packman\Attributes\Api;

/** Result of capturing a stored-value hold. */
#[Api(since: '0.1')]
final readonly class StoredValueCapture
{
    public function __construct(
        public string $holdId,
        public string $accountId,
        public Money $captured,
        public Money $balanceAfter,
        public Money $heldAfter,
        public string $journalId,
    ) {}
}
