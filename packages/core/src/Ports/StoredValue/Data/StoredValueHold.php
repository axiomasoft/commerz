<?php

declare(strict_types=1);

namespace Commerz\Ports\StoredValue\Data;

use Commerz\Money\Money;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** Snapshot of an authorization hold on a stored-value account. */
#[Api(since: '0.1')]
final readonly class StoredValueHold
{
    public function __construct(
        public string $holdId,
        public string $accountId,
        public string $orderRef,
        public Money $amount,
        public Money $captured,
        public Money $released,
        public string $state,
        public ?DateTimeImmutable $expiresAt = null,
    ) {}

    public function remaining(): Money
    {
        return $this->amount->minus($this->captured)->minus($this->released);
    }
}
