<?php

declare(strict_types=1);

namespace Commerz\Testkit\Tests\Feature;

use Commerz\CommerzServiceProvider;
use Commerz\Outbox\Contracts\OutboxConsumer;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Testkit\PackageTestCase;
use Commerz\Testkit\PluginContractTests;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

final class AuditConsumer implements OutboxConsumer
{
    public function key(): string
    {
        return 'audit.log';
    }

    public function consume(IntegrationEvent $event): void {}
}

final class AuditPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('audit.log', '1.0.0');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(OutboxConsumer::KIND, AuditConsumer::class);
    }
}

/** The contract suite run against a real plugin: it must pass for a well-behaved one. */
final class PluginContractTestsTest extends PackageTestCase
{
    use PluginContractTests;

    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class];
    }

    protected function pluginClass(): string
    {
        return AuditPlugin::class;
    }

    /** @return array<string, list<string>> */
    protected function expectedContributions(): array
    {
        return ['outbox_consumer' => ['audit.log']];
    }
}
