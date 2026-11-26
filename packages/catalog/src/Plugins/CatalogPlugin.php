<?php

declare(strict_types=1);

namespace Commerz\Catalog\Plugins;

use Commerz\Catalog\Attributes\Contracts\AttributeType;
use Commerz\Catalog\Attributes\Internal\Types\BooleanType;
use Commerz\Catalog\Attributes\Internal\Types\DateType;
use Commerz\Catalog\Attributes\Internal\Types\DecimalType;
use Commerz\Catalog\Attributes\Internal\Types\EnumType;
use Commerz\Catalog\Attributes\Internal\Types\IntegerType;
use Commerz\Catalog\Attributes\Internal\Types\LocalizedTextType;
use Commerz\Catalog\Attributes\Internal\Types\MeasureType;
use Commerz\Catalog\Attributes\Internal\Types\MultiEnumType;
use Commerz\Catalog\Attributes\Internal\Types\ReferenceType;
use Commerz\Catalog\Attributes\Internal\Types\TextType;
use Commerz\Catalog\CatalogServiceProvider;
use Commerz\Catalog\Configuration\CatalogOptions;
use Commerz\Catalog\Diagnostics\CatalogDoctorCheck;
use Commerz\Catalog\Errors\CatalogProblemDefinitions;
use Commerz\Catalog\Panels\AttributesPanel;
use Commerz\Catalog\Panels\CategoriesPanel;
use Commerz\Catalog\Panels\ProductsPanel;
use Commerz\Catalog\Panels\VariantsPanel;
use Commerz\Catalog\ProductTypes\Contracts\ProductKind;
use Commerz\Catalog\ProductTypes\Internal\Kinds\DigitalKind;
use Commerz\Catalog\ProductTypes\Internal\Kinds\PhysicalKind;
use Commerz\Catalog\ProductTypes\Internal\Kinds\ServiceKind;
use Commerz\Catalog\Projection\Internal\StorefrontOutboxProjector;
use Commerz\Catalog\Schema\CatalogSchemaSets;
use Commerz\Catalog\Variants\Internal\VariantSellables;
use Commerz\Outbox\Contracts\OutboxConsumer;
use Commerz\Plugins\CorePlugin;
use Commerz\Ports\Sellables\Contracts\SellableProvider;
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
 * The catalog's code plugin: declares `attribute_type` and `product_kind`, contributes built-in types/kinds,
 * registers the `variant` sellable provider, and exposes catalog configuration, schema, diagnostics, and panels.
 */
#[Api(since: '0.1')]
final class CatalogPlugin implements ExtensionPlugin, WithConfiguration, WithDiagnostics, WithPanels, WithSchema
{
    use Configurable;
    use OwnsSchema;
    use ProvidesDiagnostics;
    use ProvidesPanels;

    public const string ID = 'commerz.catalog';

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
        $registrar->declareKind(new ContributionKind(AttributeType::KIND, AttributeType::class, self::ID));
        $registrar->declareKind(new ContributionKind(ProductKind::KIND, ProductKind::class, self::ID));

        foreach ([
            TextType::class, LocalizedTextType::class, IntegerType::class, DecimalType::class, BooleanType::class,
            DateType::class, EnumType::class, MultiEnumType::class, MeasureType::class, ReferenceType::class,
        ] as $type) {
            $registrar->contribute(AttributeType::KIND, $type);
        }

        foreach ([PhysicalKind::class, DigitalKind::class, ServiceKind::class] as $kind) {
            $registrar->contribute(ProductKind::KIND, $kind);
        }

        $registrar->contribute(SellableProvider::KIND, VariantSellables::class);
        $registrar->contribute(OutboxConsumer::KIND, StorefrontOutboxProjector::class);
    }

    #[Override]
    public function configRoot(): string
    {
        return 'commerz-catalog';
    }

    #[Override]
    public function defaultConfigPath(): string
    {
        return dirname(__DIR__, 2).'/config/commerz-catalog.php';
    }

    /** @return class-string<CatalogOptions> */
    #[Override]
    public function optionsClass(): string
    {
        return CatalogOptions::class;
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
        return CatalogServiceProvider::NOUNS;
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
        return [CatalogSchemaSets::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function healthChecks(): array
    {
        return [CatalogDoctorCheck::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function problemProviders(): array
    {
        return [CatalogProblemDefinitions::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function panelProviders(): array
    {
        return [ProductsPanel::class, VariantsPanel::class, CategoriesPanel::class, AttributesPanel::class];
    }
}
