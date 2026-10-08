<?php

declare(strict_types=1);

use Commerz\CommerzServiceProvider;

it('registers the core service provider and config', function (): void {
    expect(app()->getProviders(CommerzServiceProvider::class))->not->toBeEmpty()
        ->and(config('commerz'))->toBeArray();
})->group('boot');
