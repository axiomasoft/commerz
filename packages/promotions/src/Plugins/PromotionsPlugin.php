<?php

declare(strict_types=1);

namespace Commerz\Promotions\Plugins;

use Commerz\Plugins\CorePlugin;
use Commerz\Promotions\Configuration\PromotionsOptions;
use Commerz\Promotions\Coupons\Internal\CouponActivator;
use Commerz\Promotions\Diagnostics\PromotionsDoctorCheck;
use Commerz\Promotions\Errors\PromotionsProblemDefinitions;
use Commerz\Promotions\Evaluation\Internal\Stages\CartPromotionsStage;
use Commerz\Promotions\Evaluation\Internal\Stages\CatalogPromotionsStage;
use Commerz\Promotions\Evaluation\Internal\Stages\DeliveryPromotionsStage;
use Commerz\Promotions\Evaluation\Internal\Stages\NearMissHintStage;
use Commerz\Promotions\Panels\CouponsPanel;
use Commerz\Promotions\Panels\PromotionsPanel;
use Commerz\Promotions\PromotionsServiceProvider;
use Commerz\Promotions\Rules\Contracts\PromotionActivator;
use Commerz\Promotions\Rules\Contracts\PromotionCondition;
use Commerz\Promotions\Rules\Contracts\PromotionEffect;
use Commerz\Promotions\Rules\Internal\Activators\AutomaticActivator;
use Commerz\Promotions\Rules\Internal\Conditions\CartQuantityAtLeast;
use Commerz\Promotions\Rules\Internal\Conditions\CartSubtotalAtLeast;
use Commerz\Promotions\Rules\Internal\Conditions\CustomerInGroup;
use Commerz\Promotions\Rules\Internal\Conditions\LineCategoryIn;
use Commerz\Promotions\Rules\Internal\Conditions\LineSellableIn;
use Commerz\Promotions\Rules\Internal\Effects\AutoAddGiftLine;
use Commerz\Promotions\Rules\Internal\Effects\BuyXGetY;
use Commerz\Promotions\Rules\Internal\Effects\DeliveryPercentOff;
use Commerz\Promotions\Rules\Internal\Effects\LineFixedOff;
use Commerz\Promotions\Rules\Internal\Effects\LinePercentOff;
use Commerz\Promotions\Rules\Internal\Effects\OrderFixedOff;
use Commerz\Promotions\Rules\Internal\Effects\OrderPercentOff;
use Commerz\Promotions\Schema\PromotionsSchemaSets;
use Commerz\Quotes\Contracts\QuoteStage;
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
 * The promotions' code plugin: declares `promotion_condition`, `promotion_effect`, and `promotion_activator`,
 * contributes the built-in rules, activators, and promotion quote stages, and exposes configuration, schema,
 * diagnostics, and panels.
 */
#[Api(since: '0.1')]
final class PromotionsPlugin implements ExtensionPlugin, WithConfiguration, WithDiagnostics, WithPanels, WithSchema
{
    use Configurable;
    use OwnsSchema;
    use ProvidesDiagnostics;
    use ProvidesPanels;

    public const string ID = 'commerz.promotions';

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
        $registrar->declareKind(new ContributionKind(PromotionCondition::KIND, PromotionCondition::class, self::ID));
        $registrar->declareKind(new ContributionKind(PromotionEffect::KIND, PromotionEffect::class, self::ID));
        $registrar->declareKind(new ContributionKind(PromotionActivator::KIND, PromotionActivator::class, self::ID));

        foreach ([
            LineSellableIn::class,
            LineCategoryIn::class,
            CustomerInGroup::class,
            CartSubtotalAtLeast::class,
            CartQuantityAtLeast::class,
        ] as $condition) {
            $registrar->contribute(PromotionCondition::KIND, $condition);
        }

        foreach ([
            LinePercentOff::class,
            LineFixedOff::class,
            OrderPercentOff::class,
            OrderFixedOff::class,
            DeliveryPercentOff::class,
            BuyXGetY::class,
            AutoAddGiftLine::class,
        ] as $effect) {
            $registrar->contribute(PromotionEffect::KIND, $effect);
        }

        $registrar->contribute(PromotionActivator::KIND, AutomaticActivator::class);
        $registrar->contribute(PromotionActivator::KIND, CouponActivator::class);

        $registrar->contribute(QuoteStage::KIND, CatalogPromotionsStage::class);
        $registrar->contribute(QuoteStage::KIND, CartPromotionsStage::class);
        $registrar->contribute(QuoteStage::KIND, DeliveryPromotionsStage::class);
        $registrar->contribute(QuoteStage::KIND, NearMissHintStage::class);
    }

    #[Override]
    public function configRoot(): string
    {
        return 'commerz-promotions';
    }

    #[Override]
    public function defaultConfigPath(): string
    {
        return dirname(__DIR__, 2).'/config/commerz-promotions.php';
    }

    /** @return class-string<PromotionsOptions> */
    #[Override]
    public function optionsClass(): string
    {
        return PromotionsOptions::class;
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
        return PromotionsServiceProvider::NOUNS;
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
        return [PromotionsSchemaSets::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function healthChecks(): array
    {
        return [PromotionsDoctorCheck::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function problemProviders(): array
    {
        return [PromotionsProblemDefinitions::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function panelProviders(): array
    {
        return [PromotionsPanel::class, CouponsPanel::class];
    }
}
