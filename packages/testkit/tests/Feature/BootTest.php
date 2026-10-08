<?php

declare(strict_types=1);

use Commerz\Testkit\PackageTestCase;

it('exposes an abstract Testbench base case', function (): void {
    expect(new ReflectionClass(PackageTestCase::class)->isAbstract())->toBeTrue();
})->group('boot');
