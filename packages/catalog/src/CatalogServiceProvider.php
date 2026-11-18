<?php

declare(strict_types=1);

namespace Commerz\Catalog;

use Commerz\Catalog\Laravel\Registrars\CatalogRegistrar;
use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Api(since: '0.1')]
final class CatalogServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars {
        register as protected registerRegistrars;
        boot as protected bootRegistrars;
    }

    public const array NOUNS = ['product', 'variant', 'option', 'attribute', 'category', 'listing'];

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        CatalogRegistrar::class,
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
                __DIR__.'/../config/commerz-catalog.php' => $this->app->configPath('commerz-catalog.php'),
            ], 'commerz-catalog-config');
        }

        $this->bootRegistrars();
    }
}
