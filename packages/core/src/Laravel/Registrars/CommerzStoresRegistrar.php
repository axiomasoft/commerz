<?php

declare(strict_types=1);

namespace Commerz\Laravel\Registrars;

use Commerz\Commerz;
use Commerz\Ports\Sellables\Contracts\Sellables;
use Commerz\Ports\Sellables\Internal\SellableRouter;
use Commerz\Quotes\Contracts\QuotePipeline;
use Commerz\Quotes\Internal\StagedQuotePipeline;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Internal\DatabaseChannels;
use Commerz\Stores\Internal\DatabaseStores;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Concerns\RegistersNothingOnBoot;
use Packman\Foundation\Contracts\PackageRegistrar;

/**
 * Registers core Stores, Channels, Sellables, QuotePipeline, and the root Commerz entrypoint.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final class CommerzStoresRegistrar implements PackageRegistrar
{
    use RegistersNothingOnBoot;

    #[Override]
    public function register(Application $app): void
    {
        $app->singleton(Stores::class, DatabaseStores::class);
        $app->singleton(Channels::class, DatabaseChannels::class);
        $app->singleton(Sellables::class, SellableRouter::class);
        $app->singleton(QuotePipeline::class, StagedQuotePipeline::class);
        $app->singleton(Commerz::class);
    }
}
