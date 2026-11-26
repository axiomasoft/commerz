<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Data;

use Commerz\B2b\PaymentTerms\Enums\CreditHoldState;
use Commerz\Money\Money;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class CreditHoldView
{
    public function __construct(
        public CreditHoldId $id,
        public CreditAccountId $creditAccountId,
        public string $reference,
        public Money $amount,
        public CreditHoldState $state,
        public ?DateTimeImmutable $dueAt,
        public DateTimeImmutable $createdAt,
    ) {}
}
