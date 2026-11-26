<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Data;

use Commerz\Errors\InvalidCommand;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** Exponential retry policy (`min(maxDelaySeconds, baseDelaySeconds * 2^(attempt - 1))`) for failed webhook deliveries. */
#[Api(since: '0.1')]
final readonly class WebhookRetryPolicy
{
    public const int DEFAULT_BASE_DELAY_SECONDS = 30;

    public const int DEFAULT_MAX_DELAY_SECONDS = 3600;

    public function __construct(
        public int $baseDelaySeconds = self::DEFAULT_BASE_DELAY_SECONDS,
        public int $maxDelaySeconds = self::DEFAULT_MAX_DELAY_SECONDS,
    ) {
        if ($baseDelaySeconds < 1 || $baseDelaySeconds > 86400) {
            throw new InvalidCommand('baseDelaySeconds', 'must be from 1 to 86400', 'webhooks.invalid_retry_policy');
        }

        if ($maxDelaySeconds < $baseDelaySeconds || $maxDelaySeconds > 604800) {
            throw new InvalidCommand('maxDelaySeconds', 'must be between baseDelaySeconds and 604800', 'webhooks.invalid_retry_policy');
        }
    }

    public static function exponential(
        int $baseDelaySeconds = self::DEFAULT_BASE_DELAY_SECONDS,
        int $maxDelaySeconds = self::DEFAULT_MAX_DELAY_SECONDS,
    ): self {
        return new self($baseDelaySeconds, $maxDelaySeconds);
    }

    public function delaySecondsForAttempt(int $attempt): int
    {
        if ($attempt < 1) {
            throw new InvalidCommand('attempt', 'must be at least 1', 'webhooks.invalid_attempt');
        }

        $exponent = min($attempt - 1, 20);

        return min($this->maxDelaySeconds, $this->baseDelaySeconds * (2 ** $exponent));
    }

    public function nextAvailableAt(DateTimeImmutable $from, int $attempt): DateTimeImmutable
    {
        $seconds = $this->delaySecondsForAttempt($attempt);

        return $from->modify("+{$seconds} seconds");
    }
}
