<?php

declare(strict_types=1);

namespace Commerz\Sales\Diagnostics;

use Closure;
use Commerz\Sales\Configuration\SalesOptions;
use Commerz\Sales\Numbering\Contracts\OrderNumberGenerator;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Override;
use Packman\Attributes\Api;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Throwable;

/**
 * Verifies `axiomasoft/commerz-sales` configuration, order numbering, and payment gateway contributions.
 */
#[Api(since: '0.1')]
final readonly class SalesDoctorCheck implements DoctorCheck
{
    /**
     * @param  (Closure(): SalesOptions)|null  $optionsResolver
     * @param  (Closure(): Contributions)|null  $contributionsResolver
     */
    public function __construct(
        private ConfigRepository $config,
        private ?Closure $optionsResolver = null,
        private ?Closure $contributionsResolver = null,
    ) {}

    #[Override]
    public function key(): string
    {
        return 'commerz.sales.configuration';
    }

    #[Override]
    public function title(): string
    {
        return 'Commerz sales configuration and gateway contributions';
    }

    /** @return list<DoctorFinding> */
    #[Override]
    public function run(DoctorContext $context): array
    {
        try {
            $options = $this->resolveOptions();
        } catch (InvalidConfiguration $exception) {
            return [DoctorFinding::error(
                code: 'commerz.sales.invalid_configuration',
                message: $exception->getMessage(),
                remediation: 'Fix the invalid entries in config/commerz-sales.php.',
            )];
        } catch (Throwable $exception) {
            return [DoctorFinding::error(
                code: 'commerz.sales.configuration_error',
                message: $exception->getMessage(),
                remediation: 'Verify config/commerz-sales.php can be compiled cleanly.',
            )];
        }

        if ($this->contributionsResolver !== null) {
            $findings = $this->checkContributions($options);

            if ($findings !== []) {
                return $findings;
            }
        }

        return [DoctorFinding::ok(
            code: 'commerz.sales.healthy',
            message: sprintf(
                'Sales configuration OK (number_generator=%s, require_delivery=%s, max_cart_lines=%d).',
                $options->numberGenerator,
                $options->requireDelivery ? 'true' : 'false',
                $options->maxCartLines,
            ),
            evidence: [
                'number_generator' => $options->numberGenerator,
                'require_delivery' => $options->requireDelivery,
                'max_lines' => $options->maxCartLines,
                'max_quantity' => $options->maxLineQuantity,
                'expire_batch' => $options->expireBatch,
                'lease_seconds' => $options->leaseSeconds,
            ],
        )];
    }

    private function resolveOptions(): SalesOptions
    {
        if ($this->optionsResolver !== null) {
            return ($this->optionsResolver)();
        }

        $raw = $this->config->get('commerz-sales', []);

        return SalesOptions::fromArray(is_array($raw) ? $raw : [], 'commerz-sales');
    }

    /** @return list<DoctorFinding> */
    private function checkContributions(SalesOptions $options): array
    {
        assert($this->contributionsResolver !== null);

        try {
            $contributions = ($this->contributionsResolver)();

            if (! $contributions->has(OrderNumberGenerator::KIND, $options->numberGenerator)) {
                return [DoctorFinding::error(
                    code: 'commerz.sales.unknown_number_generator',
                    message: sprintf('Configured order number generator "%s" is not contributed by any active plugin.', $options->numberGenerator),
                    remediation: 'Set commerz-sales.checkout.number_generator to a contributed key (such as "sequence").',
                    evidence: ['number_generator' => $options->numberGenerator],
                )];
            }

            if (! $contributions->has('payment_gateway', 'manual')) {
                return [DoctorFinding::error(
                    code: 'commerz.sales.missing_manual_gateway',
                    message: 'Built-in payment_gateway "manual" is not contributed.',
                    remediation: 'Ensure SalesPlugin is active in CommerzPlugins.',
                )];
            }
        } catch (Throwable $exception) {
            return [DoctorFinding::error(
                code: 'commerz.sales.plugin_error',
                message: $exception->getMessage(),
                remediation: 'Verify SalesPlugin declarations and plugin configuration.',
            )];
        }

        return [];
    }
}
