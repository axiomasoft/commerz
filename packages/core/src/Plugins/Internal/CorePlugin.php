<?php

declare(strict_types=1);

namespace Commerz\Plugins\Internal;

use Commerz\Outbox\Contracts\OutboxConsumer;
use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\ContributionKind;
use Commerz\Plugins\PluginManifest;
use Commerz\Plugins\PluginRegistrar;

/** The kernel's own plugin. Every other plugin implicitly loads after it, so its kinds are always available. */
final class CorePlugin implements CommerzPlugin
{
    public const string ID = 'commerz.core';

    public function manifest(): PluginManifest
    {
        return new PluginManifest(self::ID, '0.1.0');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->declareKind(new ContributionKind(OutboxConsumer::KIND, OutboxConsumer::class, self::ID));
    }
}
