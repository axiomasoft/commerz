<?php

declare(strict_types=1);

namespace Commerz\Pricing;

use Commerz\Pricing\Laravel\Registrars\PricingRegistrar;
use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Api(since: '0.1')]
final class PricingServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars {
        register as protected registerRegistrars;
        boot as protected bootRegistrars;
    }

    public const array NOUNS = ['price', 'tax'];

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        PricingRegistrar::class,
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
                __DIR__.'/../config/commerz-pricing.php' => $this->app->configPath('commerz-pricing.php'),
            ], 'commerz-pricing-config');
        }

        $this->bootRegistrars();
    }
}
