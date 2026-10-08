<?php

declare(strict_types=1);

use Commerz\Arch\ArchitectureGates;

/** @var array<string, array{kind: string, nouns: list<string>, extra?: list<string>}> $map */
$map = require __DIR__.'/ownership.php';
$repository = dirname(__DIR__, 2);

/** gate method => [fixture directory, path fragment the violation must name] */
$gates = [
    'dependencies' => ['dependencies', 'packages/catalog/composer.json'],
    'internals' => ['internals', 'packages/catalog/src/LeaksInventory.php'],
    'markers' => ['markers', 'packages/catalog/src/Unmarked.php'],
    'eloquent' => ['eloquent', 'packages/catalog/src/LeakedModel.php'],
    'tables' => ['tables', 'packages/catalog/database/migrations/2026_01_01_000001_create_foreign_table.php'],
    'floats' => ['floats', 'packages/catalog/src/Data/FloatPrice.php'],
];

foreach ($gates as $gate => [$fixture, $offender]) {
    it("{$gate} gate passes on the real tree and checks something", function () use ($gate, $map, $repository): void {
        $result = (new ArchitectureGates($repository, $map))->{$gate}();

        expect($result->checked)->toBeGreaterThan(0)
            ->and($result->violations)->toBe([], $result->describe());
    });

    it("{$gate} gate fails on its negative fixture and names the file", function () use ($gate, $fixture, $offender, $map): void {
        $result = (new ArchitectureGates(__DIR__.'/Fixtures/'.$fixture, $map))->{$gate}();

        expect($result->passes())->toBeFalse()
            ->and($result->describe())->toContain($offender);
    });
}

it('fails a gate that inspects nothing', function () use ($map): void {
    $result = (new ArchitectureGates(__DIR__.'/Fixtures/empty', $map))->markers();

    expect($result->checked)->toBe(0)
        ->and($result->passes())->toBeFalse();
});
