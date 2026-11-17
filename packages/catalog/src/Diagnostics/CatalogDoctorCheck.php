<?php

declare(strict_types=1);

namespace Commerz\Catalog\Diagnostics;

use Closure;
use Commerz\Catalog\Attributes\Contracts\AttributeType;
use Commerz\Catalog\Configuration\CatalogOptions;
use Commerz\Catalog\ProductTypes\Contracts\ProductKind;
use Commerz\Ports\Sellables\Contracts\SellableProvider;
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
 * Verifies `axiomasoft/commerz-catalog` configuration and required plugin contributions.
 */
#[Api(since: '0.1')]
final readonly class CatalogDoctorCheck implements DoctorCheck
{
    /**
     * @param  (Closure(): CatalogOptions)|null  $optionsResolver
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
        return 'commerz.catalog.configuration';
    }

    #[Override]
    public function title(): string
    {
        return 'Commerz catalog configuration and plugin contributions';
    }

    /** @return list<DoctorFinding> */
    #[Override]
    public function run(DoctorContext $context): array
    {
        try {
            $options = $this->resolveOptions();
        } catch (InvalidConfiguration $exception) {
            return [DoctorFinding::error(
                code: 'commerz.catalog.invalid_configuration',
                message: $exception->getMessage(),
                remediation: 'Fix the invalid entries in config/commerz-catalog.php.',
            )];
        } catch (Throwable $exception) {
            return [DoctorFinding::error(
                code: 'commerz.catalog.configuration_error',
                message: $exception->getMessage(),
                remediation: 'Verify config/commerz-catalog.php can be compiled cleanly.',
            )];
        }

        if ($this->contributionsResolver !== null) {
            $findings = $this->checkContributions();

            if ($findings !== []) {
                return $findings;
            }
        }

        return [DoctorFinding::ok(
            code: 'commerz.catalog.healthy',
            message: sprintf('Catalog configuration OK (max_generated_variants=%d).', $options->maxGeneratedVariants),
            evidence: ['max_generated_variants' => $options->maxGeneratedVariants],
        )];
    }

    private function resolveOptions(): CatalogOptions
    {
        if ($this->optionsResolver !== null) {
            return ($this->optionsResolver)();
        }

        $raw = $this->config->get('commerz-catalog', []);

        return CatalogOptions::fromArray(is_array($raw) ? $raw : [], 'commerz-catalog');
    }

    /** @return list<DoctorFinding> */
    private function checkContributions(): array
    {
        assert($this->contributionsResolver !== null);

        try {
            $contributions = ($this->contributionsResolver)();

            if ($contributions->keys(AttributeType::KIND) === [] || $contributions->keys(ProductKind::KIND) === [] || ! $contributions->has(SellableProvider::KIND, 'variant')) {
                return [DoctorFinding::error(
                    code: 'commerz.catalog.missing_contributions',
                    message: 'Catalog built-in attribute types, product kinds, or variant sellable provider are missing from the plugin registry.',
                    remediation: 'Ensure CatalogPlugin is active and not listed in disabled_plugins.',
                )];
            }
        } catch (Throwable $exception) {
            return [DoctorFinding::error(
                code: 'commerz.catalog.plugin_error',
                message: $exception->getMessage(),
                remediation: 'Verify CatalogPlugin declarations and plugin configuration.',
            )];
        }

        return [];
    }
}
