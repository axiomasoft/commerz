<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Rate;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class DepositMilestone
{
    public string $label;

    public function __construct(
        public Rate $share,
        public int $dueInDays,
        string $label,
    ) {
        if ($share->basisPoints < 1 || $share->basisPoints > 10_000) {
            throw new InvalidCommand('share', 'milestone share must be between 1 and 10000 basis points', 'b2b.invalid_payment_terms');
        }

        if ($dueInDays < 0 || $dueInDays > 3650) {
            throw new InvalidCommand('dueInDays', 'must be between 0 and 3650 days', 'b2b.invalid_payment_terms');
        }

        $trimmed = trim($label);

        if ($trimmed === '' || strlen($trimmed) > 120) {
            throw new InvalidCommand('label', 'must be 1..120 bytes', 'b2b.invalid_payment_terms');
        }

        $this->label = $trimmed;
    }
}
