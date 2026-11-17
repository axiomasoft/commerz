<?php

declare(strict_types=1);

namespace Commerz\Inventory\Diagnostics;

use Closure;
use Commerz\Inventory\Allocation\Contracts\AllocationStrategy;
use Commerz\Inventory\Configuration\InventoryOptions;
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
 * Verifies `axiomasoft/commerz-inventory` configuration and configured allocation strategy contribution.
 */
#[Api(since: '0.1')]
final readonly class InventoryDoctorCheck implements DoctorCheck
{
    /**
     * @param  (Closure(): InventoryOptions)|null  $optionsResolver
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
        return 'commerz.inventory.configuration';
    }

    #[Override]
    public function title(): string
    {
        return 'Commerz inventory configuration and allocation strategy';
    }

    /** @return list<DoctorFinding> */
    #[Override]
    public function run(DoctorContext $context): array
    {
        try {
            $options = $this->resolveOptions();
        } catch (InvalidConfiguration $exception) {
            return [DoctorFinding::error(
                code: 'commerz.inventory.invalid_configuration',
                message: $exception->getMessage(),
                remediation: 'Fix the invalid entries in config/commerz-inventory.php.',
            )];
        } catch (Throwable $exception) {
            return [DoctorFinding::error(
                code: 'commerz.inventory.configuration_error',
                message: $exception->getMessage(),
                remediation: 'Verify config/commerz-inventory.php can be compiled cleanly.',
            )];
        }

        if ($this->contributionsResolver !== null) {
            $findings = $this->checkStrategy($options);

            if ($findings !== []) {
                return $findings;
            }
        }

        return [DoctorFinding::ok(
            code: 'commerz.inventory.healthy',
            message: sprintf(
                'Inventory configuration OK (strategy=%s, unlinked=%s, expiry_batch=%d).',
                $options->allocationStrategy,
                $options->unlinkedSellables->value,
                $options->expiryBatch,
            ),
            evidence: [
                'allocation_strategy' => $options->allocationStrategy,
                'unlinked_sellables' => $options->unlinkedSellables->value,
                'expiry_batch' => $options->expiryBatch,
            ],
        )];
    }

    private function resolveOptions(): InventoryOptions
    {
        if ($this->optionsResolver !== null) {
            return ($this->optionsResolver)();
        }

        $raw = $this->config->get('commerz-inventory', []);

        return InventoryOptions::fromArray(is_array($raw) ? $raw : [], 'commerz-inventory');
    }

    /** @return list<DoctorFinding> */
    private function checkStrategy(InventoryOptions $options): array
    {
        assert($this->contributionsResolver !== null);

        try {
            $contributions = ($this->contributionsResolver)();

            if (! $contributions->has(AllocationStrategy::KIND, $options->allocationStrategy)) {
                return [DoctorFinding::error(
                    code: 'commerz.inventory.unknown_allocation_strategy',
                    message: sprintf('Configured allocation strategy "%s" is not contributed by any active plugin.', $options->allocationStrategy),
                    remediation: 'Set commerz-inventory.allocation_strategy to a contributed key (such as "priority" or "single_location").',
                    evidence: ['allocation_strategy' => $options->allocationStrategy],
                )];
            }
        } catch (Throwable $exception) {
            return [DoctorFinding::error(
                code: 'commerz.inventory.plugin_error',
                message: $exception->getMessage(),
                remediation: 'Verify InventoryPlugin declarations and plugin configuration.',
            )];
        }

        return [];
    }
}
