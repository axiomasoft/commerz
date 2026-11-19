<?php

declare(strict_types=1);

namespace Commerz\Promotions;

use Commerz\Laravel\CommerzPluginServiceProvider;
use Commerz\Promotions\Laravel\Registrars\PromotionsRegistrar;
use Commerz\Promotions\Plugins\PromotionsPlugin;
use Packman\Attributes\Api;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Api(since: '0.1')]
final class PromotionsServiceProvider extends CommerzPluginServiceProvider
{
    protected const string PLUGIN = PromotionsPlugin::class;

    public const array NOUNS = ['promotion', 'coupon'];

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        PromotionsRegistrar::class,
    ];
}
