<?php

declare(strict_types=1);

use Commerz\CommerzServiceProvider;
use Commerz\Configuration\CommerzOptions;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Contracts\DoctorRegistryContract;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Schema\Contracts\Migration\SchemaSets;

it('registers the core service provider, options, schema sets, problems, and doctor check', function (): void {
    expect(app()->getProviders(CommerzServiceProvider::class))->not->toBeEmpty()
        ->and(config('commerz'))->toBeArray()
        ->and(app(CommerzOptions::class))->toBeInstanceOf(CommerzOptions::class)
        ->and(app(SchemaSets::class)->all())->not->toBeEmpty()
        ->and(array_map(static fn (DoctorCheck $c): string => $c->key(), app(DoctorRegistryContract::class)->all()))->toContain('commerz.runtime.configuration')
        ->and(app(ProblemRegistry::class)->fromWire('commerz.invalid_command')->title)->toBe('Invalid Command');
})->group('boot');
