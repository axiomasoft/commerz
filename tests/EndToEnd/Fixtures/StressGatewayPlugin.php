<?php

declare(strict_types=1);

namespace Commerz\EndToEnd\Fixtures;

use Commerz\Plugins\CorePlugin;
use Commerz\Sales\Gateways\Contracts\PaymentGateway;
use Commerz\Sales\Plugins\SalesPlugin;
use Override;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

/** Registers {@see StressPaymentGateway} for the multi-process PostgreSQL concurrency stress suite. */
final class StressGatewayPlugin implements ExtensionPlugin
{
    public const string ID = 'tests.stress-gateway';

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(self::ID, '1.0.0', [CorePlugin::ID, SalesPlugin::ID]);
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(PaymentGateway::KIND, StressPaymentGateway::class);
    }
}
