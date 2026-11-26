<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Configuration;

use Commerz\Subscriptions\Billing\Data\RetryPolicy;
use Commerz\Subscriptions\Billing\Enums\RetryExhaustedAction;
use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable compiled configuration snapshot for `axiomasoft/commerz-subscriptions`.
 */
#[Api(since: '0.1')]
final readonly class SubscriptionsOptions implements OptionsFactory
{
    /** @var list<string> */
    private const array TOP_KEYS = [
        'batch_size',
        'retry_policy',
    ];

    /** @var list<string> */
    private const array RETRY_KEYS = [
        'max_attempts',
        'backoff_delays_seconds',
        'exhausted_action',
    ];

    public RetryPolicy $retryPolicy;

    public function __construct(
        public int $batchSize = 100,
        ?RetryPolicy $retryPolicy = null,
    ) {
        $this->retryPolicy = $retryPolicy ?? new RetryPolicy;
    }

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'commerz-subscriptions'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, self::TOP_KEYS, $path);

        $batchSize = self::int(
            $validator,
            array_key_exists('batch_size', $config) ? $config['batch_size'] : 100,
            1,
            1000,
            $path.'.batch_size',
        );

        $rawRetry = array_key_exists('retry_policy', $config) ? $config['retry_policy'] : [];
        if (! is_array($rawRetry)) {
            $validator->add($path.'.retry_policy', 'expected an array');
            $rawRetry = [];
        } else {
            $validator->rejectUnknownKeys($rawRetry, self::RETRY_KEYS, $path.'.retry_policy');
        }

        $maxAttempts = self::int(
            $validator,
            array_key_exists('max_attempts', $rawRetry) ? $rawRetry['max_attempts'] : 4,
            1,
            20,
            $path.'.retry_policy.max_attempts',
        );

        $rawDelays = array_key_exists('backoff_delays_seconds', $rawRetry)
            ? $rawRetry['backoff_delays_seconds']
            : [86_400, 259_200, 604_800];

        $delays = [86_400, 259_200, 604_800];
        if (! is_array($rawDelays) || ! array_is_list($rawDelays)) {
            $validator->add($path.'.retry_policy.backoff_delays_seconds', 'expected a list of positive integers');
        } elseif ($maxAttempts > 1 && $rawDelays === []) {
            $validator->add($path.'.retry_policy.backoff_delays_seconds', 'expected at least one delay when max_attempts > 1');
        } else {
            $parsedDelays = [];
            foreach ($rawDelays as $index => $delay) {
                $parsedDelays[] = self::int($validator, $delay, 1, 31_536_000, "{$path}.retry_policy.backoff_delays_seconds.{$index}");
            }
            $delays = $parsedDelays;
        }

        $rawAction = array_key_exists('exhausted_action', $rawRetry)
            ? $rawRetry['exhausted_action']
            : RetryExhaustedAction::Cancel->value;

        $exhaustedAction = RetryExhaustedAction::Cancel;
        if (! is_string($rawAction) || RetryExhaustedAction::tryFrom($rawAction) === null) {
            $validator->add($path.'.retry_policy.exhausted_action', "expected one of 'cancel', 'pause', 'keep_past_due'");
        } else {
            $exhaustedAction = RetryExhaustedAction::from($rawAction);
        }

        $validator->failIfAny();

        return new self(
            batchSize: $batchSize,
            retryPolicy: new RetryPolicy($maxAttempts, $delays, $exhaustedAction),
        );
    }

    private static function int(ConfigurationValidator $validator, mixed $value, int $min, int $max, string $path): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            $validator->add($path, "expected an integer from {$min} to {$max}");

            return $min;
        }

        return $value;
    }
}
