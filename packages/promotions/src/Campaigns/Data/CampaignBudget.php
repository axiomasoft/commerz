<?php

declare(strict_types=1);

namespace Commerz\Promotions\Campaigns\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Packman\Attributes\Api;

/**
 * Budget and usage caps of a {@see Campaign} (`spend_cap_minor`, `usage_cap`) and their current consumed totals.
 * Enforced atomically under PostgreSQL row locking (`FOR KEY SHARE` + conditional `UPDATE`, INV-PRM-001).
 */
#[Api(since: '0.1')]
final readonly class CampaignBudget
{
    public function __construct(
        public ?int $spendCapMinor = null,
        public int $spentMinor = 0,
        public ?int $usageCap = null,
        public int $used = 0,
        public ?Currency $currency = null,
    ) {
        if ($spendCapMinor !== null && $spendCapMinor <= 0) {
            throw new InvalidCommand('spendCapMinor', 'must be greater than zero', 'promotions.invalid_budget');
        }

        if ($spendCapMinor !== null && ! $currency instanceof Currency) {
            throw new InvalidCommand('currency', 'a spend cap requires a currency', 'promotions.invalid_budget');
        }

        if ($spentMinor < 0) {
            throw new InvalidCommand('spentMinor', 'must not be negative', 'promotions.invalid_budget');
        }

        if ($usageCap !== null && ($usageCap < 1 || $usageCap > 2_147_483_647)) {
            throw new InvalidCommand('usageCap', 'must be a positive 32-bit integer', 'promotions.invalid_limits');
        }

        if ($used < 0) {
            throw new InvalidCommand('used', 'must not be negative', 'promotions.invalid_limits');
        }
    }

    public function spendCap(): ?Money
    {
        return $this->spendCapMinor !== null && $this->currency instanceof Currency
            ? Money::ofMinor($this->spendCapMinor, $this->currency)
            : null;
    }

    public function spent(): ?Money
    {
        return $this->currency instanceof Currency
            ? Money::ofMinor($this->spentMinor, $this->currency)
            : null;
    }

    public function remainingSpendMinor(): ?int
    {
        return $this->spendCapMinor === null ? null : max(0, $this->spendCapMinor - $this->spentMinor);
    }

    public function remainingSpend(): ?Money
    {
        $remaining = $this->remainingSpendMinor();

        return $remaining !== null && $this->currency instanceof Currency
            ? Money::ofMinor($remaining, $this->currency)
            : null;
    }

    public function remainingUsage(): ?int
    {
        return $this->usageCap === null ? null : max(0, $this->usageCap - $this->used);
    }

    public function hasCaps(): bool
    {
        return $this->spendCapMinor !== null || $this->usageCap !== null;
    }

    public function isExhausted(): bool
    {
        if ($this->usageCap !== null && $this->used >= $this->usageCap) {
            return true;
        }

        return $this->spendCapMinor !== null && $this->spentMinor >= $this->spendCapMinor;
    }

    public function canAfford(int $discountMinor, int $uses = 1): bool
    {
        if ($discountMinor < 0 || $uses < 0) {
            return false;
        }

        if ($this->usageCap !== null && $uses > $this->usageCap - $this->used) {
            return false;
        }

        if ($this->spendCapMinor !== null && $discountMinor > $this->spendCapMinor - $this->spentMinor) {
            return false;
        }

        return true;
    }
}
