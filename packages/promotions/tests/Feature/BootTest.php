<?php

declare(strict_types=1);

use Commerz\Promotions\PromotionsServiceProvider;

it('registers the promotions service provider and config', function (): void {
    expect(app()->getProviders(PromotionsServiceProvider::class))->not->toBeEmpty()
        ->and(config('commerz-promotions'))->toBeArray();
})->group('boot');
