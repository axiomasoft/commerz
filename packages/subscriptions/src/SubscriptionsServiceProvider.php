<?php

declare(strict_types=1);

namespace Commerz\Subscriptions;

use Commerz\Laravel\CommerzPluginServiceProvider;
use Commerz\Subscriptions\Laravel\Registrars\SubscriptionsRegistrar;
use Commerz\Subscriptions\Plugins\SubscriptionsPlugin;
use Packman\Attributes\Api;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Api(since: '0.1')]
final class SubscriptionsServiceProvider extends CommerzPluginServiceProvider
{
    protected const string PLUGIN = SubscriptionsPlugin::class;

    public const array NOUNS = ['subscription'];

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        SubscriptionsRegistrar::class,
    ];
}
