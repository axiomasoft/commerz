<?php

declare(strict_types=1);

use Commerz\Subscriptions\Errors\Enums\SubscriptionsErrorCode;
use Commerz\Subscriptions\Errors\SubscriptionsProblemDefinitions;
use Packman\Errors\Data\ProblemDefinition;

it('registers problem definitions for every subscriptions error code with canonical URN prefix', function (): void {
    $definitions = (new SubscriptionsProblemDefinitions)->definitions();
    $definedCodes = array_map(static fn (ProblemDefinition $d): string => $d->code->value(), $definitions);
    $allCodes = array_map(static fn (SubscriptionsErrorCode $c): string => $c->value(), SubscriptionsErrorCode::cases());

    sort($definedCodes);
    sort($allCodes);

    expect($definedCodes)->toBe($allCodes);

    foreach ($definitions as $definition) {
        expect($definition->type)->toBe('urn:packman:problem:'.$definition->code->value());
    }
});
