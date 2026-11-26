<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Diagnostics;

use Closure;
use Commerz\Subscriptions\Configuration\SubscriptionsOptions;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Override;
use Packman\Attributes\Api;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Throwable;

/**
 * Verifies `axiomasoft/commerz-subscriptions` configuration health.
 */
#[Api(since: '0.1')]
final readonly class SubscriptionsDoctorCheck implements DoctorCheck
{
    /** @param  (Closure(): SubscriptionsOptions)|null  $optionsResolver */
    public function __construct(
        private ConfigRepository $config,
        private ?Closure $optionsResolver = null,
    ) {}

    #[Override]
    public function key(): string
    {
        return 'commerz.subscriptions.configuration';
    }

    #[Override]
    public function title(): string
    {
        return 'Commerz subscriptions configuration';
    }

    /** @return list<DoctorFinding> */
    #[Override]
    public function run(DoctorContext $context): array
    {
        try {
            $options = $this->resolveOptions();
        } catch (InvalidConfiguration $exception) {
            return [DoctorFinding::error(
                code: 'commerz.subscriptions.invalid_configuration',
                message: $exception->getMessage(),
                remediation: 'Fix the invalid entries in config/commerz-subscriptions.php.',
            )];
        } catch (Throwable $exception) {
            return [DoctorFinding::error(
                code: 'commerz.subscriptions.configuration_error',
                message: $exception->getMessage(),
                remediation: 'Verify config/commerz-subscriptions.php can be compiled cleanly.',
            )];
        }

        return [DoctorFinding::ok(
            code: 'commerz.subscriptions.healthy',
            message: sprintf(
                'Subscriptions configuration OK (batch_size=%d, max_attempts=%d, exhausted_action=%s).',
                $options->batchSize,
                $options->retryPolicy->maxAttempts,
                $options->retryPolicy->exhaustedAction->value,
            ),
            evidence: [
                'batch_size' => $options->batchSize,
                'max_attempts' => $options->retryPolicy->maxAttempts,
                'exhausted_action' => $options->retryPolicy->exhaustedAction->value,
            ],
        )];
    }

    private function resolveOptions(): SubscriptionsOptions
    {
        if ($this->optionsResolver !== null) {
            return ($this->optionsResolver)();
        }

        $raw = $this->config->get('commerz-subscriptions', []);

        return SubscriptionsOptions::fromArray(is_array($raw) ? $raw : [], 'commerz-subscriptions');
    }
}
