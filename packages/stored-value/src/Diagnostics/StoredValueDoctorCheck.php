<?php

declare(strict_types=1);

namespace Commerz\StoredValue\Diagnostics;

use Closure;
use Commerz\StoredValue\Configuration\StoredValueOptions;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Override;
use Packman\Attributes\Api;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Throwable;

/**
 * Verifies `axiomasoft/commerz-stored-value` configuration health.
 */
#[Api(since: '0.1')]
final readonly class StoredValueDoctorCheck implements DoctorCheck
{
    /** @param  (Closure(): StoredValueOptions)|null  $optionsResolver */
    public function __construct(
        private ConfigRepository $config,
        private ?Closure $optionsResolver = null,
    ) {}

    #[Override]
    public function key(): string
    {
        return 'commerz.stored_value.configuration';
    }

    #[Override]
    public function title(): string
    {
        return 'Commerz stored value configuration';
    }

    /** @return list<DoctorFinding> */
    #[Override]
    public function run(DoctorContext $context): array
    {
        try {
            $options = $this->resolveOptions();
        } catch (InvalidConfiguration $exception) {
            return [DoctorFinding::error(
                code: 'commerz.stored_value.invalid_configuration',
                message: $exception->getMessage(),
                remediation: 'Fix the invalid entries in config/commerz-stored-value.php.',
            )];
        } catch (Throwable $exception) {
            return [DoctorFinding::error(
                code: 'commerz.stored_value.configuration_error',
                message: $exception->getMessage(),
                remediation: 'Verify config/commerz-stored-value.php can be compiled cleanly.',
            )];
        }

        return [DoctorFinding::ok(
            code: 'commerz.stored_value.healthy',
            message: sprintf(
                'Stored value configuration OK (max_tenders_per_quote=%d, code_length=%d, prefix=%s).',
                $options->maxTendersPerQuote,
                $options->codeLength,
                $options->codePrefix,
            ),
            evidence: [
                'max_tenders_per_quote' => $options->maxTendersPerQuote,
                'code_length' => $options->codeLength,
                'code_prefix' => $options->codePrefix,
            ],
        )];
    }

    private function resolveOptions(): StoredValueOptions
    {
        if ($this->optionsResolver !== null) {
            return ($this->optionsResolver)();
        }

        $raw = $this->config->get('commerz-stored-value', []);

        return StoredValueOptions::fromArray(is_array($raw) ? $raw : [], 'commerz-stored-value');
    }
}
