<?php

declare(strict_types=1);

namespace Commerz\Internal;

use Commerz\Outbox\Contracts\OutboxConsumer;
use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\ContributionKind;
use Commerz\Plugins\Internal\PluginGraphCompiler;
use Commerz\Plugins\PluginManifest;
use Commerz\Plugins\PluginRegistrar;
use Commerz\Ports\Sellables\Contracts\SellableProvider;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Internal\BaseStage;
use Commerz\Quotes\Internal\TotalsStage;

/** The kernel's own plugin. Every other plugin implicitly loads after it, so its kinds are always available. */
final class CorePlugin implements CommerzPlugin
{
    public const string ID = PluginGraphCompiler::CORE_ID;

    public function manifest(): PluginManifest
    {
        return new PluginManifest(self::ID, '0.1.0');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->declareKind(new ContributionKind(OutboxConsumer::KIND, OutboxConsumer::class, self::ID));
        $registrar->declareKind(new ContributionKind(SellableProvider::KIND, SellableProvider::class, self::ID));
        $registrar->declareKind(new ContributionKind(QuoteStage::KIND, QuoteStage::class, self::ID));

        $registrar->quoteStage(BaseStage::class);
        $registrar->quoteStage(TotalsStage::class);
    }
}
