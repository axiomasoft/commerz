<?php

declare(strict_types=1);

use Commerz\Promotions\Configuration\PromotionsOptions;
use Commerz\Promotions\PromotionsServiceProvider;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Contracts\DoctorRegistryContract;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Schema\Contracts\Migration\SchemaSets;

it('registers the promotions service provider, options, schema sets, problems, and doctor check', function (): void {
    expect(app()->getProviders(PromotionsServiceProvider::class))->not->toBeEmpty()
        ->and(config('commerz-promotions'))->toBeArray()
        ->and(app(PromotionsOptions::class))->toBeInstanceOf(PromotionsOptions::class)
        ->and(app(SchemaSets::class)->all())->not->toBeEmpty()
        ->and(array_map(static fn (DoctorCheck $c): string => $c->key(), app(DoctorRegistryContract::class)->all()))->toContain('commerz.promotions.configuration')
        ->and(app(ProblemRegistry::class)->fromWire('promotions.promotion_not_found')->title)->toBe('Promotion Not Found');
})->group('boot');
