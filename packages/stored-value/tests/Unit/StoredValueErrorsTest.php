<?php

declare(strict_types=1);

use Commerz\StoredValue\Errors\Enums\StoredValueErrorCode;
use Commerz\StoredValue\Errors\StoredValueProblemDefinitions;
use Packman\Errors\Data\ProblemDefinition;

it('registers problem definitions for every stored-value error code with canonical URN prefix', function (): void {
    $definitions = (new StoredValueProblemDefinitions)->definitions();
    $definedCodes = array_map(static fn (ProblemDefinition $d): string => $d->code->value(), $definitions);
    $allCodes = array_map(static fn (StoredValueErrorCode $c): string => $c->value(), StoredValueErrorCode::cases());

    sort($definedCodes);
    sort($allCodes);

    expect($definedCodes)->toBe($allCodes);

    foreach ($definitions as $definition) {
        expect($definition->type)->toBe('urn:packman:problem:'.$definition->code->value());
    }
});
