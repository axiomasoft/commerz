<?php

declare(strict_types=1);

namespace Commerz\Ports\StoredValue\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Ports\StoredValue\Enums\StoredValueKind;
use Packman\Attributes\Api;

/** One stored-value instrument's contribution toward a quote's post-tax grand total. */
#[Api(since: '0.1')]
final readonly class AppliedTender
{
    public function __construct(
        public string $accountId,
        public StoredValueKind $kind,
        public Money $amount,
        public Money $remainingBalance,
        public ?string $codeLast4 = null,
    ) {
        if (trim($accountId) === '') {
            throw new InvalidCommand('accountId', 'must be non-empty', 'stored_value.invalid_account');
        }

        if (! $amount->isPositive()) {
            throw new InvalidCommand('amount', 'applied tender amount must be positive', 'stored_value.invalid_amount');
        }

        if ($remainingBalance->isNegative()) {
            throw new InvalidCommand('remainingBalance', 'remaining balance cannot be negative', 'stored_value.invalid_amount');
        }

        if (! $amount->currency->equals($remainingBalance->currency)) {
            throw new InvalidCommand('remainingBalance', 'currency must match applied tender currency', 'stored_value.currency_mismatch');
        }
    }
}
