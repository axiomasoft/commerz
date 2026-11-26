<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Data;

use Commerz\B2b\PaymentTerms\Enums\PaymentTermsType;
use Commerz\B2b\PaymentTerms\Exceptions\InvalidPaymentTerms;
use Commerz\Money\Money;
use Commerz\Money\MoneyAllocator;
use Commerz\Money\Rate;
use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Api;

/**
 * B2B payment terms value object: Net15, Net30, Net60, or a multi-milestone deposit schedule summing to 10,000 bp.
 */
#[Api(since: '0.1')]
final readonly class PaymentTerms
{
    public string $code;

    /** @var list<DepositMilestone> */
    public array $milestones;

    /** @param list<DepositMilestone> $milestones */
    public function __construct(
        string $code,
        public PaymentTermsType $type,
        public int $netDays,
        array $milestones,
    ) {
        $normalizedCode = strtolower(trim($code));

        if (preg_match('/\A[a-z0-9][a-z0-9_\-]{0,63}\z/', $normalizedCode) !== 1) {
            throw new InvalidPaymentTerms("Payment terms code '{$code}' must match [a-z0-9][a-z0-9_-]{0,63}.");
        }

        if ($netDays < 0 || $netDays > 3650) {
            throw new InvalidPaymentTerms('netDays must be between 0 and 3650.');
        }

        if ($milestones === []) {
            throw new InvalidPaymentTerms('Payment terms require at least one milestone.');
        }

        $sumBp = 0;
        $maxDueDays = 0;

        foreach ($milestones as $milestone) {
            $sumBp += $milestone->share->basisPoints;
            if ($milestone->dueInDays > $maxDueDays) {
                $maxDueDays = $milestone->dueInDays;
            }
        }

        if ($sumBp !== 10_000) {
            throw new InvalidPaymentTerms("Milestone shares must sum to exactly 10000 basis points (100%); got {$sumBp}.");
        }

        if ($type === PaymentTermsType::DepositSchedule && count($milestones) < 2) {
            throw new InvalidPaymentTerms('DepositSchedule payment terms require at least two milestones.');
        }

        if ($maxDueDays !== $netDays) {
            throw new InvalidPaymentTerms("netDays ({$netDays}) must equal the latest milestone dueInDays ({$maxDueDays}).");
        }

        $this->code = $normalizedCode;
        $this->milestones = $milestones;
    }

    public static function net15(string $code = 'net_15'): self
    {
        return new self(
            code: $code,
            type: PaymentTermsType::Net15,
            netDays: 15,
            milestones: [new DepositMilestone(Rate::bp(10_000), 15, 'Net 15')],
        );
    }

    public static function net30(string $code = 'net_30'): self
    {
        return new self(
            code: $code,
            type: PaymentTermsType::Net30,
            netDays: 30,
            milestones: [new DepositMilestone(Rate::bp(10_000), 30, 'Net 30')],
        );
    }

    public static function net60(string $code = 'net_60'): self
    {
        return new self(
            code: $code,
            type: PaymentTermsType::Net60,
            netDays: 60,
            milestones: [new DepositMilestone(Rate::bp(10_000), 60, 'Net 60')],
        );
    }

    /** @param list<DepositMilestone> $milestones */
    public static function depositSchedule(string $code, array $milestones): self
    {
        $maxDays = 0;
        foreach ($milestones as $milestone) {
            if ($milestone->dueInDays > $maxDays) {
                $maxDays = $milestone->dueInDays;
            }
        }

        return new self(
            code: $code,
            type: PaymentTermsType::DepositSchedule,
            netDays: $maxDays,
            milestones: $milestones,
        );
    }

    public function schedule(Money $total, DateTimeImmutable $issuedAt): PaymentSchedule
    {
        if ($total->isNegative()) {
            throw new InvalidPaymentTerms('Cannot schedule payment terms for a negative total.');
        }

        $utcIssued = $issuedAt->setTimezone(new DateTimeZone('UTC'));
        $weights = array_map(static fn (DepositMilestone $m): int => $m->share->basisPoints, $this->milestones);
        $allocated = MoneyAllocator::allocate($total, $weights);

        $dueNow = Money::zero($total->currency);
        $installments = [];

        foreach ($this->milestones as $index => $milestone) {
            $shareAmount = $allocated[$index];
            $isDeposit = $milestone->dueInDays === 0;
            $dueAt = $milestone->dueInDays === 0
                ? $utcIssued
                : $utcIssued->modify("+{$milestone->dueInDays} days");

            if ($isDeposit) {
                $dueNow = $dueNow->plus($shareAmount);
            }

            $installments[] = new ScheduledInstallment(
                label: $milestone->label,
                share: $milestone->share,
                amount: $shareAmount,
                dueInDays: $milestone->dueInDays,
                dueAt: $dueAt,
                isDeposit: $isDeposit,
            );
        }

        $finalDueAt = $this->netDays === 0
            ? $utcIssued
            : $utcIssued->modify("+{$this->netDays} days");

        return new PaymentSchedule(
            totalAmount: $total,
            dueNowAmount: $dueNow,
            deferredAmount: $total->minus($dueNow),
            finalDueAt: $finalDueAt,
            installments: $installments,
        );
    }
}
