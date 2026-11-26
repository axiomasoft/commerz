<?php

declare(strict_types=1);

use Commerz\B2b\Configuration\B2bOptions;
use Commerz\B2b\Diagnostics\B2bDoctorCheck;
use Commerz\B2b\Errors\B2bProblemDefinitions;
use Commerz\B2b\Errors\Enums\B2bErrorCode;
use Commerz\B2b\Panels\B2bPanel;
use Illuminate\Config\Repository;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Enums\CheckStatus;
use Packman\Doctor\Enums\Severity;
use Packman\Errors\Data\ProblemDefinition;

it('compiles B2bOptions defaults and custom values and rejects invalid configuration', function (): void {
    $defaults = B2bOptions::fromArray([]);

    expect($defaults->defaultQuoteValidityDays)->toBe(30)
        ->and($defaults->maxHierarchyDepth)->toBe(10)
        ->and($defaults->allowSelfApproval)->toBeFalse()
        ->and($defaults->quoteRequestPrefix)->toBe('RFQ');

    $custom = B2bOptions::fromArray([
        'default_quote_validity_days' => 14,
        'max_hierarchy_depth' => 5,
        'allow_self_approval' => true,
        'quote_request_prefix' => 'B2BRFQ',
    ]);

    expect($custom->defaultQuoteValidityDays)->toBe(14)
        ->and($custom->maxHierarchyDepth)->toBe(5)
        ->and($custom->allowSelfApproval)->toBeTrue()
        ->and($custom->quoteRequestPrefix)->toBe('B2BRFQ');

    expect(static fn (): B2bOptions => B2bOptions::fromArray(['unknown_key' => 1]))
        ->toThrow(InvalidConfiguration::class);

    expect(static fn (): B2bOptions => B2bOptions::fromArray(['default_quote_validity_days' => 0]))
        ->toThrow(InvalidConfiguration::class);

    expect(static fn (): B2bOptions => B2bOptions::fromArray(['quote_request_prefix' => 'bad-prefix!']))
        ->toThrow(InvalidConfiguration::class);
});

it('registers problem definitions for every B2bErrorCode with canonical URN prefix', function (): void {
    $definitions = (new B2bProblemDefinitions)->definitions();
    $definedCodes = array_map(static fn (ProblemDefinition $d): string => $d->code->value(), $definitions);
    $allCodes = array_map(static fn (B2bErrorCode $c): string => $c->value(), B2bErrorCode::cases());

    sort($definedCodes);
    sort($allCodes);

    expect($definedCodes)->toBe($allCodes);

    foreach ($definitions as $definition) {
        expect($definition->type)->toBe('urn:packman:problem:'.$definition->code->value());
    }
});

it('reports healthy and unhealthy findings from B2bDoctorCheck and exposes B2bPanel descriptor', function (): void {
    $okCheck = new B2bDoctorCheck(new Repository(['commerz-b2b' => ['default_quote_validity_days' => 21]]));
    $findings = $okCheck->run(new DoctorContext);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->status)->toBe(CheckStatus::Ok)
        ->and($findings[0]->severity)->toBe(Severity::Info)
        ->and($findings[0]->code)->toBe('commerz.b2b.healthy');

    $badCheck = new B2bDoctorCheck(new Repository(['commerz-b2b' => ['max_hierarchy_depth' => 999]]));
    $badFindings = $badCheck->run(new DoctorContext);

    expect($badFindings)->toHaveCount(1)
        ->and($badFindings[0]->status)->toBe(CheckStatus::Error)
        ->and($badFindings[0]->severity)->toBe(Severity::Error)
        ->and($badFindings[0]->code)->toBe('commerz.b2b.invalid_configuration');

    $descriptor = (new B2bPanel)->descriptor();
    expect($descriptor->key)->toBe('commerz.b2b.commerce')
        ->and($descriptor->owningModule)->toBe('axiomasoft/commerz-b2b')
        ->and($descriptor->supportedActions)->toContain('stage_quotes', 'approve_flows');
});
