<?php

declare(strict_types=1);

namespace Commerz\Sales\Plugins;

use Commerz\Plugins\CorePlugin;
use Commerz\Sales\Configuration\SalesOptions;
use Commerz\Sales\Diagnostics\SalesDoctorCheck;
use Commerz\Sales\Errors\SalesProblemDefinitions;
use Commerz\Sales\Gateways\Contracts\PaymentGateway;
use Commerz\Sales\Gateways\Internal\ManualGateway;
use Commerz\Sales\Numbering\Contracts\OrderNumberGenerator;
use Commerz\Sales\Numbering\Internal\SequenceOrderNumbers;
use Commerz\Sales\Panels\FulfillmentsPanel;
use Commerz\Sales\Panels\OrdersPanel;
use Commerz\Sales\Panels\PaymentsPanel;
use Commerz\Sales\Panels\ReturnsPanel;
use Commerz\Sales\Payments\Contracts\PaymentCustomization;
use Commerz\Sales\SalesServiceProvider;
use Commerz\Sales\Schema\SalesSchemaSets;
use Override;
use Packman\Attributes\Api;
use Packman\Extensions\Concerns\Composition\ProvidesPanels;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Concerns\Runtime\ProvidesDiagnostics;
use Packman\Extensions\Contracts\Composition\WithPanels;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Contributions\ContributionKind;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * The sales' code plugin: declares `payment_gateway` and `order_number_generator`, contributes `manual` and
 * `sequence`, and exposes sales configuration, schema, diagnostics, and panels.
 */
#[Api(since: '0.1')]
final class SalesPlugin implements ExtensionPlugin, WithConfiguration, WithDiagnostics, WithPanels, WithSchema
{
    use Configurable;
    use OwnsSchema;
    use ProvidesDiagnostics;
    use ProvidesPanels;

    public const string ID = 'commerz.sales';

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: self::ID,
            version: '0.1.0',
            requires: [CorePlugin::ID],
            resourceRoot: dirname(__DIR__, 2),
            databases: ['pgsql'],
        );
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->declareKind(new ContributionKind(PaymentGateway::KIND, PaymentGateway::class, self::ID));
        $registrar->declareKind(new ContributionKind(OrderNumberGenerator::KIND, OrderNumberGenerator::class, self::ID));
        $registrar->declareKind(new ContributionKind(PaymentCustomization::KIND, PaymentCustomization::class, self::ID));
        $registrar->contribute(PaymentGateway::KIND, ManualGateway::class);
        $registrar->contribute(OrderNumberGenerator::KIND, SequenceOrderNumbers::class);
    }

    #[Override]
    public function configRoot(): string
    {
        return 'commerz-sales';
    }

    #[Override]
    public function defaultConfigPath(): string
    {
        return dirname(__DIR__, 2).'/config/commerz-sales.php';
    }

    /** @return class-string<SalesOptions> */
    #[Override]
    public function optionsClass(): string
    {
        return SalesOptions::class;
    }

    #[Override]
    public function tablePrefix(): string
    {
        return 'cmz_';
    }

    /** @return list<string> */
    #[Override]
    public function tableNouns(): array
    {
        return SalesServiceProvider::NOUNS;
    }

    #[Override]
    public function migrationPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations';
    }

    /** @return list<class-string> */
    #[Override]
    public function schemaSetProviders(): array
    {
        return [SalesSchemaSets::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function healthChecks(): array
    {
        return [SalesDoctorCheck::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function problemProviders(): array
    {
        return [SalesProblemDefinitions::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function panelProviders(): array
    {
        return [OrdersPanel::class, PaymentsPanel::class, FulfillmentsPanel::class, ReturnsPanel::class];
    }
}
