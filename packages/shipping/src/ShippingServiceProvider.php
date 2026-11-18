<?php

declare(strict_types=1);

namespace Commerz\Shipping;

use Commerz\Shipping\Laravel\Registrars\ShippingRegistrar;
use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Api(since: '0.1')]
final class ShippingServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars {
        register as protected registerRegistrars;
        boot as protected bootRegistrars;
    }

    public const array NOUNS = ['shipping'];

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        ShippingRegistrar::class,
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
                __DIR__.'/../config/commerz-shipping.php' => $this->app->configPath('commerz-shipping.php'),
            ], 'commerz-shipping-config');
        }

        $this->bootRegistrars();
    }
}
