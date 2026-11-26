<?php

declare(strict_types=1);

use Commerz\B2b\B2b;
use Commerz\B2b\B2bServiceProvider;
use Commerz\B2b\Configuration\B2bOptions;
use Commerz\B2b\Facades\B2b as B2bFacade;
use Commerz\B2b\Quotes\Contracts\B2bQuotes;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Contracts\DoctorRegistryContract;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Schema\Contracts\Migration\SchemaSets;

it('registers the B2B service provider, options, schema sets, problems, doctor check, and facade', function (): void {
    expect(app()->getProviders(B2bServiceProvider::class))->not->toBeEmpty()
        ->and(config('commerz-b2b'))->toBeArray()
        ->and(app(B2bOptions::class))->toBeInstanceOf(B2bOptions::class)
        ->and(app(B2b::class))->toBeInstanceOf(B2b::class)
        ->and(B2bFacade::quotes())->toBeInstanceOf(B2bQuotes::class)
        ->and(app(SchemaSets::class)->all())->not->toBeEmpty()
        ->and(array_map(static fn (DoctorCheck $c): string => $c->key(), app(DoctorRegistryContract::class)->all()))
        ->toContain('commerz.b2b.configuration')
        ->and(app(ProblemRegistry::class)->fromWire('b2b.recurring_selling_plan_forbidden')->title)
        ->toBe('Recurring SellingPlan Forbidden With PaymentTerms');
})->group('boot');
