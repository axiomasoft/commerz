<?php

declare(strict_types=1);

use Commerz\Outbox\Contracts\OutboxConsumer;
use Commerz\Subscriptions\Configuration\SubscriptionsOptions;
use Commerz\Subscriptions\Subscriptions;
use Commerz\Subscriptions\SubscriptionsServiceProvider;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Contracts\DoctorRegistryContract;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Schema\Contracts\Migration\SchemaSets;

it('registers the subscriptions service provider, options, schema sets, problems, doctor check, and outbox consumer', function (): void {
    expect(app()->getProviders(SubscriptionsServiceProvider::class))->not->toBeEmpty()
        ->and(config('commerz-subscriptions'))->toBeArray()
        ->and(app(SubscriptionsOptions::class))->toBeInstanceOf(SubscriptionsOptions::class)
        ->and(app(Subscriptions::class))->toBeInstanceOf(Subscriptions::class)
        ->and(app(SchemaSets::class)->all())->not->toBeEmpty()
        ->and(app(Contributions::class)->keys(OutboxConsumer::KIND))->toContain('subscriptions.order_placed')
        ->and(array_map(static fn (DoctorCheck $c): string => $c->key(), app(DoctorRegistryContract::class)->all()))
        ->toContain('commerz.subscriptions.configuration')
        ->and(app(ProblemRegistry::class)->fromWire('subscriptions.renewal_payment_failed')->title)
        ->toBe('Subscription Renewal Payment Failed');
})->group('boot');
