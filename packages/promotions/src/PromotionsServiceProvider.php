<?php

declare(strict_types=1);

namespace Commerz\Promotions;

use Commerz\Promotions\Laravel\Registrars\PromotionsRegistrar;
use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Api(since: '0.1')]
final class PromotionsServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars {
        register as protected registerRegistrars;
        boot as protected bootRegistrars;
    }

    public const array NOUNS = ['promotion', 'coupon'];

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        PromotionsRegistrar::class,
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
                __DIR__.'/../config/commerz-promotions.php' => $this->app->configPath('commerz-promotions.php'),
            ], 'commerz-promotions-config');
        }

        $this->bootRegistrars();
    }
}
