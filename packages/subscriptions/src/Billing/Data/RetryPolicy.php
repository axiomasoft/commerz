<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Billing\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Subscriptions\Billing\Enums\RetryExhaustedAction;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Api;

/**
 * Dunning retry schedule for failed recurring billing attempts.
 */
#[Api(since: '0.1')]
final readonly class RetryPolicy
{
    /** @var list<int> */
    public array $backoffDelaysSeconds;

    /**
     * @param  int  $maxAttempts  total attempts per cycle (1 initial attempt + up to maxAttempts - 1 retries)
     * @param  list<int>  $backoffDelaysSeconds  positive wait times in seconds before each retry
     */
    public function __construct(
        public int $maxAttempts = 4,
        array $backoffDelaysSeconds = [86_400, 259_200, 604_800],
        public RetryExhaustedAction $exhaustedAction = RetryExhaustedAction::Cancel,
    ) {
        if ($maxAttempts < 1 || $maxAttempts > 20) {
            throw new InvalidCommand('maxAttempts', 'must be between 1 and 20', 'subscriptions.invalid_retry_policy');
        }

        if ($maxAttempts > 1 && $backoffDelaysSeconds === []) {
            throw new InvalidCommand(
                'backoffDelaysSeconds',
                'must provide at least one delay when maxAttempts > 1',
                'subscriptions.invalid_retry_policy',
            );
        }

        $delays = [];
        foreach ($backoffDelaysSeconds as $delay) {
            if ($delay < 1 || $delay > 31_536_000) {
                throw new InvalidCommand(
                    'backoffDelaysSeconds',
                    'each retry delay must be between 1 and 31536000 seconds',
                    'subscriptions.invalid_retry_policy',
                );
            }

            $delays[] = $delay;
        }

        $this->backoffDelaysSeconds = $delays;
    }

    public function hasRetriesRemaining(int $completedAttempts): bool
    {
        return $completedAttempts < $this->maxAttempts;
    }

    public function delayForAttempt(int $failedAttemptNumber): ?int
    {
        if ($failedAttemptNumber < 1 || ! $this->hasRetriesRemaining($failedAttemptNumber)) {
            return null;
        }

        if ($this->backoffDelaysSeconds === []) {
            return null;
        }

        $index = min($failedAttemptNumber - 1, count($this->backoffDelaysSeconds) - 1);

        return $this->backoffDelaysSeconds[$index];
    }

    public function nextAttemptAt(DateTimeImmutable $failedAt, int $failedAttemptNumber): ?DateTimeImmutable
    {
        $delay = $this->delayForAttempt($failedAttemptNumber);
        if ($delay === null) {
            return null;
        }

        return $failedAt->setTimezone(new DateTimeZone('UTC'))->add(new DateInterval("PT{$delay}S"));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'max_attempts' => $this->maxAttempts,
            'backoff_delays_seconds' => $this->backoffDelaysSeconds,
            'exhausted_action' => $this->exhaustedAction->value,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $maxAttempts = isset($data['max_attempts']) && is_int($data['max_attempts']) ? $data['max_attempts'] : 4;
        $rawDelays = isset($data['backoff_delays_seconds']) && is_array($data['backoff_delays_seconds'])
            ? $data['backoff_delays_seconds']
            : [86_400, 259_200, 604_800];

        $delays = [];
        foreach ($rawDelays as $delay) {
            if (! is_int($delay)) {
                throw new InvalidCommand('backoffDelaysSeconds', 'each delay must be an integer', 'subscriptions.invalid_retry_policy');
            }
            $delays[] = $delay;
        }

        $rawAction = isset($data['exhausted_action']) && is_string($data['exhausted_action'])
            ? $data['exhausted_action']
            : RetryExhaustedAction::Cancel->value;

        $action = RetryExhaustedAction::tryFrom($rawAction)
            ?? throw new InvalidCommand('exhaustedAction', "unknown retry exhausted action '{$rawAction}'", 'subscriptions.invalid_retry_policy');

        return new self($maxAttempts, $delays, $action);
    }
}
