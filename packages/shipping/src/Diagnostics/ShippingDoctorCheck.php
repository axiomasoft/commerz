<?php

declare(strict_types=1);

namespace Commerz\Shipping\Diagnostics;

use Closure;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Shipping\Carriers\Contracts\Carrier;
use Commerz\Shipping\Configuration\ShippingOptions;
use Commerz\Shipping\Rates\Contracts\DeliveryRate;
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
 * Verifies `axiomasoft/commerz-shipping` configuration, rate/carrier contributions, and delivery quote stage.
 */
#[Api(since: '0.1')]
final readonly class ShippingDoctorCheck implements DoctorCheck
{
    /**
     * @param  (Closure(): ShippingOptions)|null  $optionsResolver
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
        return 'commerz.shipping.configuration';
    }

    #[Override]
    public function title(): string
    {
        return 'Commerz shipping configuration and delivery contributions';
    }

    /** @return list<DoctorFinding> */
    #[Override]
    public function run(DoctorContext $context): array
    {
        try {
            $options = $this->resolveOptions();
        } catch (InvalidConfiguration $exception) {
            return [DoctorFinding::error(
                code: 'commerz.shipping.invalid_configuration',
                message: $exception->getMessage(),
                remediation: 'Fix the invalid entries in config/commerz-shipping.php.',
            )];
        } catch (Throwable $exception) {
            return [DoctorFinding::error(
                code: 'commerz.shipping.configuration_error',
                message: $exception->getMessage(),
                remediation: 'Verify config/commerz-shipping.php can be compiled cleanly.',
            )];
        }

        if ($this->contributionsResolver !== null) {
            $findings = $this->checkContributions();

            if ($findings !== []) {
                return $findings;
            }
        }

        return [DoctorFinding::ok(
            code: 'commerz.shipping.healthy',
            message: sprintf(
                'Shipping configuration OK (tax_included=%s, max_zone_members=%d, lease_seconds=%d).',
                $options->taxIncluded ? 'true' : 'false',
                $options->maxZoneMembers,
                $options->bookingLeaseSeconds,
            ),
            evidence: [
                'tax_included' => $options->taxIncluded,
                'max_zone_members' => $options->maxZoneMembers,
                'booking_lease_seconds' => $options->bookingLeaseSeconds,
            ],
        )];
    }

    private function resolveOptions(): ShippingOptions
    {
        if ($this->optionsResolver !== null) {
            return ($this->optionsResolver)();
        }

        $raw = $this->config->get('commerz-shipping', []);

        return ShippingOptions::fromArray(is_array($raw) ? $raw : [], 'commerz-shipping');
    }

    /** @return list<DoctorFinding> */
    private function checkContributions(): array
    {
        assert($this->contributionsResolver !== null);

        try {
            $contributions = ($this->contributionsResolver)();

            if (! $contributions->has(DeliveryRate::KIND, 'flat')
                || ! $contributions->has(DeliveryRate::KIND, 'weight_table')
                || ! $contributions->has(Carrier::KIND, 'manual')) {
                return [DoctorFinding::error(
                    code: 'commerz.shipping.missing_contributions',
                    message: 'Shipping built-in delivery rates or manual carrier are missing from the plugin registry.',
                    remediation: 'Ensure ShippingPlugin is active and not listed in disabled_plugins.',
                )];
            }

            if (! $contributions->has(QuoteStage::KIND, QuoteAnchor::Delivery->value)) {
                return [DoctorFinding::error(
                    code: 'commerz.shipping.missing_delivery_stage',
                    message: 'Built-in quote_stage "delivery" is not contributed.',
                    remediation: 'Ensure ShippingPlugin is active in CommerzPlugins.',
                )];
            }
        } catch (Throwable $exception) {
            return [DoctorFinding::error(
                code: 'commerz.shipping.plugin_error',
                message: $exception->getMessage(),
                remediation: 'Verify ShippingPlugin declarations and plugin configuration.',
            )];
        }

        return [];
    }
}
