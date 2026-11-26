<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Internal;

use Commerz\Sales\Payments\Enums\PaymentCollectionStatus;
use Commerz\Sales\Payments\Enums\PaymentSessionKind;
use Commerz\Sales\Payments\Enums\PaymentSessionStatus;
use Packman\Attributes\Internal;

/**
 * Pure split-tender state machine for {@see PaymentCollectionRow} and its {@see PaymentSessionRow} sessions.
 */
#[Internal]
final class PaymentCollectionStateMachine
{
    /**
     * Derives the collection's status and aggregate amounts from its sessions.
     *
     * @param  list<PaymentSessionRow>  $sessions
     * @return array{status: PaymentCollectionStatus, authorized: int, captured: int, committed: int}
     */
    public static function derive(int $targetMinor, array $sessions, bool $cancelled = false): array
    {
        $openAuthorized = 0;
        $captured = 0;
        $committed = 0;

        foreach ($sessions as $session) {
            $captured += $session->captured;

            if (! $session->captureClosed
                && in_array($session->status, [PaymentSessionStatus::Authorized, PaymentSessionStatus::PartiallyCaptured], true)) {
                $openAuthorized += max(0, $session->authorized - $session->captured);
            }

            if (! $session->status->isActive()) {
                continue;
            }

            $committed += match ($session->status) {
                PaymentSessionStatus::Captured => $session->captured,
                PaymentSessionStatus::PartiallyCaptured => $session->captureClosed
                    ? $session->captured
                    : max($session->authorized, $session->captured),
                PaymentSessionStatus::Authorized => $session->authorized,
                default => $session->amount,
            };
        }

        $status = match (true) {
            $targetMinor > 0 && $captured >= $targetMinor => PaymentCollectionStatus::Captured,
            $captured > 0 => PaymentCollectionStatus::PartiallyCaptured,
            $cancelled => PaymentCollectionStatus::Cancelled,
            $openAuthorized >= $targetMinor && $targetMinor > 0 => PaymentCollectionStatus::Authorized,
            $openAuthorized > 0 => PaymentCollectionStatus::PartiallyAuthorized,
            default => PaymentCollectionStatus::NotPaid,
        };

        return [
            'status' => $status,
            'authorized' => $openAuthorized,
            'captured' => $captured,
            'committed' => $committed,
        ];
    }

    public static function canAcceptNewSession(PaymentCollectionStatus $status): bool
    {
        return ! in_array($status, [PaymentCollectionStatus::Cancelled, PaymentCollectionStatus::Captured], true);
    }

    public static function canCapture(PaymentSessionRow $session): bool
    {
        return ! $session->captureClosed
            && in_array($session->status, [PaymentSessionStatus::Authorized, PaymentSessionStatus::PartiallyCaptured], true);
    }

    public static function canIncrementalAuthorize(PaymentSessionRow $session): bool
    {
        return $session->kind === PaymentSessionKind::Gateway
            && ! $session->captureClosed
            && in_array($session->status, [PaymentSessionStatus::Authorized, PaymentSessionStatus::PartiallyCaptured], true);
    }

    public static function canVoid(PaymentSessionRow $session): bool
    {
        return $session->captured === 0
            && in_array($session->status, [PaymentSessionStatus::Pending, PaymentSessionStatus::RequiresAction, PaymentSessionStatus::Authorized], true);
    }
}
