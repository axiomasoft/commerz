<?php

declare(strict_types=1);

use Commerz\Ports\StoredValue\Contracts\StoredValueTender;
use Commerz\StoredValue\Configuration\StoredValueOptions;
use Commerz\StoredValue\StoredValueServiceProvider;
use Commerz\StoredValue\Tender\Internal\DatabaseStoredValueTender;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Contracts\DoctorRegistryContract;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Schema\Contracts\Migration\SchemaSets;

it('registers the stored-value service provider, options, schema sets, problems, doctor check, and port override', function (): void {
    expect(app()->getProviders(StoredValueServiceProvider::class))->not->toBeEmpty()
        ->and(config('commerz-stored-value'))->toBeArray()
        ->and(app(StoredValueOptions::class))->toBeInstanceOf(StoredValueOptions::class)
        ->and(app(StoredValueTender::class))->toBeInstanceOf(DatabaseStoredValueTender::class)
        ->and(app(SchemaSets::class)->all())->not->toBeEmpty()
        ->and(array_map(static fn (DoctorCheck $c): string => $c->key(), app(DoctorRegistryContract::class)->all()))
        ->toContain('commerz.stored_value.configuration')
        ->and(app(ProblemRegistry::class)->fromWire('stored-value.insufficient_balance')->title)
        ->toBe('Insufficient Stored Value Balance');
})->group('boot');
