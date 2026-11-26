<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Data;

use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\PaymentTerms\Enums\CreditAccountStatus;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class CreditAccountView
{
    public Money $availableCredit;

    public function __construct(
        public CreditAccountId $id,
        public BusinessUnitId $businessUnitId,
        public Currency $currency,
        public CreditAccountStatus $status,
        public Money $creditLimit,
        public Money $usedCredit,
        public Money $reservedCredit,
        public int $version,
        public DateTimeImmutable $updatedAt,
    ) {
        $this->availableCredit = $creditLimit->minus($usedCredit)->minus($reservedCredit);
    }
}
