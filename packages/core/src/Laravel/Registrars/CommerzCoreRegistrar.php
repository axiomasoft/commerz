<?php

declare(strict_types=1);

namespace Commerz\Laravel\Registrars;

use Commerz\Configuration\CommerzOptions;
use Commerz\CustomFields\Contracts\CustomFieldIndexer;
use Commerz\CustomFields\Contracts\CustomFieldQuery;
use Commerz\CustomFields\Contracts\CustomFieldRegistry;
use Commerz\CustomFields\Internal\DatabaseCustomFieldIndexer;
use Commerz\CustomFields\Internal\DatabaseCustomFieldQuery;
use Commerz\CustomFields\Internal\InMemoryCustomFieldRegistry;
use Commerz\Diagnostics\CommerzDoctorCheck;
use Commerz\Internal\Console\InstallCommand;
use Commerz\Links\Contracts\CrossModuleQuery;
use Commerz\Links\Contracts\LinkRegistry;
use Commerz\Links\Internal\DatabaseCrossModuleQuery;
use Commerz\Links\Internal\DatabaseLinkRegistry;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\OperationReceipts;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Operations\Internal\ConnectionCommittedEvents;
use Commerz\Operations\Internal\Console\PruneOperationsCommand;
use Commerz\Operations\Internal\DatabaseOperationReceipts;
use Commerz\Operations\Internal\DatabaseTransactions;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Events\IntegrationEventDelivered;
use Commerz\Outbox\Internal\Console\RelayOutboxCommand;
use Commerz\Outbox\Internal\DatabaseOutbox;
use Commerz\Outbox\Internal\OutboxRelay;
use Commerz\Plugins\CorePlugin;
use Commerz\Plugins\PluginInspector as CommerzPluginInspector;
use Commerz\Schema\CommerzSchemaSets;
use Commerz\Schema\Internal\LegacyFingerprint;
use Commerz\Schema\TableNames;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\Internal\SystemClock;
use Commerz\Webhooks\Contracts\InboundWebhookGateway;
use Commerz\Webhooks\Contracts\WebhookSubscriptions;
use Commerz\Webhooks\Contracts\WebhookTransport;
use Commerz\Webhooks\Internal\Console\DispatchWebhooksCommand;
use Commerz\Webhooks\Internal\CurlWebhookTransport;
use Commerz\Webhooks\Internal\DatabaseInboundWebhookGateway;
use Commerz\Webhooks\Internal\DatabaseWebhookSubscriptions;
use Commerz\Webhooks\Internal\WebhookOutboxListener;
use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Laravel\ConfigSectionRegistration;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Data\Compiler\TableNames as PackmanTables;
use Packman\Schema\Laravel\SchemaExtensions;

/**
 * Wires Commerz's core extension plugin, options, schema ownership, diagnostics, panels, outbox, and operations.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final class CommerzCoreRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->singletonIf(CorePlugin::class);

        ConfigSectionRegistration::own($app, 'commerz', static function (array $section): void {
            CommerzOptions::fromArray($section, 'commerz');
        });

        CommerzSchemaSets::governMigrations($app);

        $app->singleton(CommerzDoctorCheck::class, static fn (Application $app): CommerzDoctorCheck => new CommerzDoctorCheck(
            config: $app->make(ConfigRepository::class),
            connections: $app->make(ConnectionResolverInterface::class),
            optionsResolver: static fn (): CommerzOptions => $app->make(CommerzOptions::class),
            tablesResolver: static fn (): TableNames => $app->make(TableNames::class),
            pluginsResolver: static fn (): PluginInspector => $app->make(PluginInspector::class),
        ));

        self::bindTableNames($app);

        $app->singletonIf(Clock::class, SystemClock::class);
        $app->singletonIf(CustomFieldRegistry::class, InMemoryCustomFieldRegistry::class);
        $app->singletonIf(CustomFieldIndexer::class, DatabaseCustomFieldIndexer::class);
        $app->singletonIf(CustomFieldQuery::class, DatabaseCustomFieldQuery::class);
        $app->singleton(Transactions::class, DatabaseTransactions::class);
        $app->singleton(CommittedEvents::class, ConnectionCommittedEvents::class);
        $app->singleton(OperationReceipts::class, DatabaseOperationReceipts::class);
        $app->singleton(Outbox::class, DatabaseOutbox::class);
        $app->singleton(OutboxRelay::class);
        $app->singleton(CommerzPluginInspector::class);
        $app->singleton(LinkRegistry::class, DatabaseLinkRegistry::class);
        $app->singleton(CrossModuleQuery::class, DatabaseCrossModuleQuery::class);
        $app->singletonIf(WebhookTransport::class, CurlWebhookTransport::class);
        $app->singletonIf(WebhookSubscriptions::class, DatabaseWebhookSubscriptions::class);
        $app->singletonIf(InboundWebhookGateway::class, DatabaseInboundWebhookGateway::class);
    }

    #[Override]
    public function boot(Application $app): void
    {
        $dispatcher = $app->make(Dispatcher::class);
        $dispatcher->listen(IntegrationEventDelivered::class, WebhookOutboxListener::class);

        if ($app->runningInConsole()) {
            $dispatcher->listen(ArtisanStarting::class, static function (ArtisanStarting $event): void {
                $event->artisan->resolveCommands([
                    InstallCommand::class,
                    RelayOutboxCommand::class,
                    DispatchWebhooksCommand::class,
                    PruneOperationsCommand::class,
                ]);
            });
        }
    }

    private static function bindTableNames(Application $app): void
    {
        $app->singletonIf(TableNames::class, static fn (Application $app): TableNames => new TableNames(static function () use ($app): PackmanTables {
            $options = $app->make(CommerzOptions::class);

            return new PackmanTables(
                prefix: $options->tablePrefix,
                ownership: SchemaExtensions::ownershipFor($app->make(SchemaOwnership::class), $app->make(CorePlugin::class)->tablePrefix()),
                connections: $app->make('db'),
                connection: $options->connection,
                configPath: 'commerz.table_prefix',
                owner: TableNames::OWNER,
                ownsLegacy: LegacyFingerprint::owns(...),
            );
        }));
    }
}
