<?php

declare(strict_types=1);

namespace Commerz\Pricing\Diagnostics;

use Closure;
use Commerz\Pricing\Calculation\Contracts\TaxCalculator;
use Commerz\Pricing\Configuration\PricingOptions;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Enums\QuoteAnchor;
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
 * Verifies `axiomasoft/commerz-pricing` configuration, tax calculator contribution, and `tax` quote stage.
 */
#[Api(since: '0.1')]
final readonly class PricingDoctorCheck implements DoctorCheck
{
    /**
     * @param  (Closure(): PricingOptions)|null  $optionsResolver
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
        return 'commerz.pricing.configuration';
    }

    #[Override]
    public function title(): string
    {
        return 'Commerz pricing configuration and tax contributions';
    }

    /** @return list<DoctorFinding> */
    #[Override]
    public function run(DoctorContext $context): array
    {
        try {
            $options = $this->resolveOptions();
        } catch (InvalidConfiguration $exception) {
            return [DoctorFinding::error(
                code: 'commerz.pricing.invalid_configuration',
                message: $exception->getMessage(),
                remediation: 'Fix the invalid entries in config/commerz-pricing.php.',
            )];
        } catch (Throwable $exception) {
            return [DoctorFinding::error(
                code: 'commerz.pricing.configuration_error',
                message: $exception->getMessage(),
                remediation: 'Verify config/commerz-pricing.php can be compiled cleanly.',
            )];
        }

        if ($this->contributionsResolver !== null) {
            $findings = $this->checkContributions($options);

            if ($findings !== []) {
                return $findings;
            }
        }

        return [DoctorFinding::ok(
            code: 'commerz.pricing.healthy',
            message: sprintf(
                'Pricing configuration OK (tax_calculator=%s, default_class=%s, rounding=%s).',
                $options->taxCalculator,
                $options->defaultTaxClass,
                $options->taxRounding->value,
            ),
            evidence: [
                'fallback_to_sellable' => $options->fallbackToSellable,
                'tax_calculator' => $options->taxCalculator,
                'default_class' => $options->defaultTaxClass,
                'delivery_class' => $options->deliveryTaxClass,
                'default_zone' => $options->defaultTaxZone,
                'rounding' => $options->taxRounding->value,
            ],
        )];
    }

    private function resolveOptions(): PricingOptions
    {
        if ($this->optionsResolver !== null) {
            return ($this->optionsResolver)();
        }

        $raw = $this->config->get('commerz-pricing', []);

        return PricingOptions::fromArray(is_array($raw) ? $raw : [], 'commerz-pricing');
    }

    /** @return list<DoctorFinding> */
    private function checkContributions(PricingOptions $options): array
    {
        assert($this->contributionsResolver !== null);

        try {
            $contributions = ($this->contributionsResolver)();

            if (! $contributions->has(TaxCalculator::KIND, $options->taxCalculator)) {
                return [DoctorFinding::error(
                    code: 'commerz.pricing.unknown_tax_calculator',
                    message: sprintf('Configured tax calculator "%s" is not contributed by any active plugin.', $options->taxCalculator),
                    remediation: 'Set commerz-pricing.tax.calculator to a contributed key (such as "rates").',
                    evidence: ['tax_calculator' => $options->taxCalculator],
                )];
            }

            if (! $contributions->has(QuoteStage::KIND, QuoteAnchor::Tax->value)) {
                return [DoctorFinding::error(
                    code: 'commerz.pricing.missing_tax_stage',
                    message: 'Built-in quote_stage "tax" is not contributed.',
                    remediation: 'Ensure PricingPlugin is active in CommerzPlugins.',
                )];
            }
        } catch (Throwable $exception) {
            return [DoctorFinding::error(
                code: 'commerz.pricing.plugin_error',
                message: $exception->getMessage(),
                remediation: 'Verify PricingPlugin declarations and plugin configuration.',
            )];
        }

        return [];
    }
}
