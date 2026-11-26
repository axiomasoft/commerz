<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Data;

use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ReserveCredit
{
    public string $reference;

    public function __construct(
        public BusinessUnitId $businessUnitId,
        string $reference,
        public Money $amount,
        public ?DateTimeImmutable $dueAt = null,
    ) {
        $trimmed = trim($reference);

        if ($trimmed === '' || strlen($trimmed) > 191) {
            throw new InvalidCommand('reference', 'must be 1..191 bytes', 'b2b.invalid_reference');
        }

        if (! $amount->isPositive()) {
            throw new InvalidCommand('amount', 'credit reservation amount must be positive', 'b2b.invalid_amount');
        }

        $this->reference = $trimmed;
    }
}
