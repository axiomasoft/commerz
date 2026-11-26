<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Plugins;

use Commerz\Outbox\Contracts\OutboxConsumer;
use Commerz\Plugins\CorePlugin;
use Commerz\Subscriptions\Configuration\SubscriptionsOptions;
use Commerz\Subscriptions\Diagnostics\SubscriptionsDoctorCheck;
use Commerz\Subscriptions\Errors\SubscriptionsProblemDefinitions;
use Commerz\Subscriptions\Lifecycle\Internal\OrderPlacedOutboxConsumer;
use Commerz\Subscriptions\Panels\SubscriptionsPanel;
use Commerz\Subscriptions\Schema\SubscriptionsSchemaSets;
use Commerz\Subscriptions\SubscriptionsServiceProvider;
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
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * The subscriptions code plugin: contributes the `orders.order_placed` Outbox consumer and exposes subscriptions
 * configuration, schema ownership, diagnostics, problem definitions, and headless panel.
 */
#[Api(since: '0.1')]
final class SubscriptionsPlugin implements ExtensionPlugin, WithConfiguration, WithDiagnostics, WithPanels, WithSchema
{
    use Configurable;
    use OwnsSchema;
    use ProvidesDiagnostics;
    use ProvidesPanels;

    public const string ID = 'commerz.subscriptions';

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
        $registrar->contribute(OutboxConsumer::KIND, OrderPlacedOutboxConsumer::class);
    }

    #[Override]
    public function configRoot(): string
    {
        return 'commerz-subscriptions';
    }

    #[Override]
    public function defaultConfigPath(): string
    {
        return dirname(__DIR__, 2).'/config/commerz-subscriptions.php';
    }

    /** @return class-string<SubscriptionsOptions> */
    #[Override]
    public function optionsClass(): string
    {
        return SubscriptionsOptions::class;
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
        return SubscriptionsServiceProvider::NOUNS;
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
        return [SubscriptionsSchemaSets::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function healthChecks(): array
    {
        return [SubscriptionsDoctorCheck::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function problemProviders(): array
    {
        return [SubscriptionsProblemDefinitions::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function panelProviders(): array
    {
        return [SubscriptionsPanel::class];
    }
}
