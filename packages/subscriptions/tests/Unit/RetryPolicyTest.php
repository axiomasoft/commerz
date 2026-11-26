<?php

declare(strict_types=1);

use Commerz\Subscriptions\Billing\Data\RetryPolicy;
use Commerz\Subscriptions\Billing\Enums\RetryExhaustedAction;

it('calculates retry backoff timestamps and exhaustion boundaries', function (): void {
    $policy = new RetryPolicy(
        maxAttempts: 3,
        backoffDelaysSeconds: [3_600, 86_400],
        exhaustedAction: RetryExhaustedAction::KeepPastDue,
    );

    $failedAt = new DateTimeImmutable('2026-11-26T12:00:00Z');

    expect($policy->hasRetriesRemaining(1))->toBeTrue()
        ->and($policy->nextAttemptAt($failedAt, 1)?->format('Y-m-d\TH:i:s\Z'))->toBe('2026-11-26T13:00:00Z')
        ->and($policy->hasRetriesRemaining(2))->toBeTrue()
        ->and($policy->nextAttemptAt($failedAt, 2)?->format('Y-m-d\TH:i:s\Z'))->toBe('2026-11-27T12:00:00Z')
        ->and($policy->hasRetriesRemaining(3))->toBeFalse()
        ->and($policy->nextAttemptAt($failedAt, 3))->toBeNull()
        ->and(RetryPolicy::fromArray($policy->toArray())->toArray())->toBe($policy->toArray());
});
