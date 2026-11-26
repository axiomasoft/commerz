<?php

declare(strict_types=1);

use Commerz\Subscriptions\Billing\Enums\RetryExhaustedAction;
use Commerz\Subscriptions\Configuration\SubscriptionsOptions;
use Packman\Config\Exceptions\InvalidConfiguration;

it('compiles default subscriptions options and validates bounds', function (): void {
    $defaults = SubscriptionsOptions::fromArray([]);

    expect($defaults->batchSize)->toBe(100)
        ->and($defaults->retryPolicy->maxAttempts)->toBe(4)
        ->and($defaults->retryPolicy->backoffDelaysSeconds)->toBe([86_400, 259_200, 604_800])
        ->and($defaults->retryPolicy->exhaustedAction)->toBe(RetryExhaustedAction::Cancel);

    $custom = SubscriptionsOptions::fromArray([
        'batch_size' => 50,
        'retry_policy' => [
            'max_attempts' => 3,
            'backoff_delays_seconds' => [3_600, 7_200],
            'exhausted_action' => 'pause',
        ],
    ]);

    expect($custom->batchSize)->toBe(50)
        ->and($custom->retryPolicy->maxAttempts)->toBe(3)
        ->and($custom->retryPolicy->backoffDelaysSeconds)->toBe([3_600, 7_200])
        ->and($custom->retryPolicy->exhaustedAction)->toBe(RetryExhaustedAction::Pause);

    expect(fn () => SubscriptionsOptions::fromArray(['unknown' => true]))->toThrow(InvalidConfiguration::class)
        ->and(fn () => SubscriptionsOptions::fromArray(['batch_size' => 0]))->toThrow(InvalidConfiguration::class)
        ->and(fn () => SubscriptionsOptions::fromArray(['retry_policy' => ['exhausted_action' => 'drop']]))->toThrow(InvalidConfiguration::class);
});
