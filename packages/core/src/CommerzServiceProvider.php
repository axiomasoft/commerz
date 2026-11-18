<?php

declare(strict_types=1);

namespace Commerz;

use Commerz\Laravel\Registrars\CommerzCoreRegistrar;
use Commerz\Laravel\Registrars\CommerzPortsRegistrar;
use Commerz\Laravel\Registrars\CommerzStoresRegistrar;
use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Api(since: '0.1')]
final class CommerzServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars {
        register as protected registerRegistrars;
        boot as protected bootRegistrars;
    }

    /** Table nouns owned by the core package. */
    public const array NOUNS = ['store', 'channel', 'schema', 'operation', 'outbox'];

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        CommerzCoreRegistrar::class,
        CommerzPortsRegistrar::class,
        CommerzStoresRegistrar::class,
    ];

    #[Override]
    public function register(): void
    {
        $this->registerRegistrars();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/commerz.php' => $this->app->configPath('commerz.php'),
            ], 'commerz-config');
        }

        $this->bootRegistrars();
    }
}
