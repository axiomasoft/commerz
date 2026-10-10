<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Internal;

use Commerz\Sales\Payments\Enums\PaymentStatus;
use LogicException;

/**
 * The status of a payment only moves forward (sales-design#payments, D33). Ranks: `initiating` and `unknown` 0,
 * `pending` and `requires_action` 1, `authorized` 2, `captured` 3; `failed` and `cancelled` are final. Captured money
 * is the strongest fact: it applies from any status, a final one included, and only grows. Anything else that does
 * not move the payment forward is ignored, so a late or repeated observation never undoes a newer one.
 */
final class PaymentTransitions
{
    /**
     * @param  int|null  $amount  minor units authorized or captured; the whole payment when the observer does not say
     * @param  string|null  $actionUrl  where the customer continues, for `requires_action`
     * @return PaymentRow|null the payment after the observation, or null when it is ignored
     */
    public static function apply(PaymentRow $current, PaymentStatus $observed, ?int $amount = null, ?string $actionUrl = null): ?PaymentRow
    {
        $rank = self::rank($current->status);

        return match ($observed) {
            PaymentStatus::Captured => self::capture($current, $amount ?? $current->amount),
            PaymentStatus::Authorized => $rank >= 0 && $rank < 2
                ? $current->with(PaymentStatus::Authorized, $amount ?? $current->amount, $current->captured, $current->actionUrl)
                : null,
            PaymentStatus::Pending => $rank === 0
                ? $current->with($observed, $current->authorized, $current->captured, $actionUrl)
                : null,
            PaymentStatus::RequiresAction => $rank >= 0 && $rank <= 1
                && ($current->status !== $observed || $current->actionUrl !== $actionUrl)
                ? $current->with($observed, $current->authorized, $current->captured, $actionUrl)
                : null,
            PaymentStatus::Failed => $rank >= 0 && $rank <= 1
                ? $current->with(PaymentStatus::Failed, $current->authorized, $current->captured, $current->actionUrl)
                : null,
            PaymentStatus::Cancelled => $rank >= 0 && $rank <= 2
                ? $current->with(PaymentStatus::Cancelled, $current->authorized, $current->captured, $current->actionUrl)
                : null,
            PaymentStatus::Initiating, PaymentStatus::Unknown => throw new LogicException("'{$observed->value}' is not observed at a gateway."),
        };
    }

    private static function capture(PaymentRow $current, int $amount): ?PaymentRow
    {
        // Captured again: the amount only grows, and the same amount is a repeat.
        if ($current->status === PaymentStatus::Captured) {
            return $amount > $current->captured
                ? $current->with(PaymentStatus::Captured, max($current->authorized, $amount), $amount, $current->actionUrl)
                : null;
        }

        return $current->with(PaymentStatus::Captured, max($current->authorized, $amount), $amount, $current->actionUrl);
    }

    /** -1 for the final `failed` and `cancelled`. */
    private static function rank(PaymentStatus $status): int
    {
        return match ($status) {
            PaymentStatus::Initiating, PaymentStatus::Unknown => 0,
            PaymentStatus::Pending, PaymentStatus::RequiresAction => 1,
            PaymentStatus::Authorized => 2,
            PaymentStatus::Captured => 3,
            PaymentStatus::Failed, PaymentStatus::Cancelled => -1,
        };
    }
}
