<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Renewals\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Subscriptions\Renewals\Contracts\RenewalOrderCreator;
use Packman\Attributes\Api;

/**
 * Result returned by {@see RenewalOrderCreator}.
 */
#[Api(since: '0.1')]
final readonly class RenewalOrderResult
{
    private function __construct(
        public bool $succeeded,
        public ?string $orderRef,
        public ?Money $chargedAmount,
        public ?string $failureReason,
    ) {}

    public static function succeeded(string $orderRef, Money $chargedAmount): self
    {
        if (trim($orderRef) === '' || strlen($orderRef) > 191) {
            throw new InvalidCommand('orderRef', 'must be 1..191 non-blank bytes', 'subscriptions.invalid_command');
        }

        if ($chargedAmount->isNegative()) {
            throw new InvalidCommand('chargedAmount', 'must not be negative', 'subscriptions.invalid_command');
        }

        return new self(true, $orderRef, $chargedAmount, null);
    }

    public static function failed(string $reason): self
    {
        $clean = trim($reason) === '' ? 'renewal_payment_declined' : trim($reason);

        return new self(false, null, null, mb_substr($clean, 0, 500, 'UTF-8'));
    }
}
