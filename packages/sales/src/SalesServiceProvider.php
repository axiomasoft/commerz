<?php

declare(strict_types=1);

namespace Commerz\Sales;

use Commerz\Sales\Laravel\Registrars\SalesRegistrar;
use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Api(since: '0.1')]
final class SalesServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars {
        register as protected registerRegistrars;
        boot as protected bootRegistrars;
    }

    public const array NOUNS = ['cart', 'checkout', 'order', 'payment', 'fulfillment', 'return', 'refund'];

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        SalesRegistrar::class,
    ];

    #[Override]
    public function register(): void
    {
        $this->registerRegistrars();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/commerz-sales.php' => $this->app->configPath('commerz-sales.php'),
            ], 'commerz-sales-config');
        }

        $this->bootRegistrars();
    }
}
