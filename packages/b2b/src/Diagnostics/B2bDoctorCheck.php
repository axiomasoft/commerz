<?php

declare(strict_types=1);

namespace Commerz\B2b\Diagnostics;

use Closure;
use Commerz\B2b\Configuration\B2bOptions;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Override;
use Packman\Attributes\Api;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Throwable;

/**
 * Verifies `axiomasoft/commerz-b2b` configuration health.
 */
#[Api(since: '0.1')]
final readonly class B2bDoctorCheck implements DoctorCheck
{
    /** @param (Closure(): B2bOptions)|null $optionsResolver */
    public function __construct(
        private ConfigRepository $config,
        private ?Closure $optionsResolver = null,
    ) {}

    #[Override]
    public function key(): string
    {
        return 'commerz.b2b.configuration';
    }

    #[Override]
    public function title(): string
    {
        return 'Commerz B2B configuration';
    }

    /** @return list<DoctorFinding> */
    #[Override]
    public function run(DoctorContext $context): array
    {
        try {
            $options = $this->resolveOptions();
        } catch (InvalidConfiguration $exception) {
            return [DoctorFinding::error(
                code: 'commerz.b2b.invalid_configuration',
                message: $exception->getMessage(),
                remediation: 'Fix the invalid entries in config/commerz-b2b.php.',
            )];
        } catch (Throwable $exception) {
            return [DoctorFinding::error(
                code: 'commerz.b2b.configuration_error',
                message: $exception->getMessage(),
                remediation: 'Verify config/commerz-b2b.php can be compiled cleanly.',
            )];
        }

        return [DoctorFinding::ok(
            code: 'commerz.b2b.healthy',
            message: sprintf(
                'B2B configuration OK (default_quote_validity_days=%d, max_hierarchy_depth=%d, prefix=%s).',
                $options->defaultQuoteValidityDays,
                $options->maxHierarchyDepth,
                $options->quoteRequestPrefix,
            ),
            evidence: [
                'default_quote_validity_days' => $options->defaultQuoteValidityDays,
                'max_hierarchy_depth' => $options->maxHierarchyDepth,
                'allow_self_approval' => $options->allowSelfApproval,
                'quote_request_prefix' => $options->quoteRequestPrefix,
            ],
        )];
    }

    private function resolveOptions(): B2bOptions
    {
        if ($this->optionsResolver !== null) {
            return ($this->optionsResolver)();
        }

        $raw = $this->config->get('commerz-b2b', []);

        return B2bOptions::fromArray(is_array($raw) ? $raw : [], 'commerz-b2b');
    }
}
