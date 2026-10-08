<?php

declare(strict_types=1);

use Commerz\Sales\SalesServiceProvider;

it('registers the sales service provider and config', function (): void {
    expect(app()->getProviders(SalesServiceProvider::class))->not->toBeEmpty()
        ->and(config('commerz-sales'))->toBeArray();
})->group('boot');
