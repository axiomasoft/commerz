<?php

declare(strict_types=1);

use Commerz\Testkit\PackageTestCase;
use Symfony\Component\Process\Process;

it('exposes an abstract Testbench base case', function (): void {
    expect(new ReflectionClass(PackageTestCase::class)->isAbstract())->toBeTrue();
})->group('boot');

it('stops before migrations when the inherited database is not isolated', function (): void {
    $root = dirname(__DIR__, 4);
    $process = new Process([PHP_BINARY, $root.'/vendor/bin/pest', 'packages/core/tests/Feature/BootTest.php', '--compact'], $root, ['DB_DATABASE' => 'commerz_audit_unsafe']);
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getOutput())->toContain('database ending in _test')
        ->and($process->getOutput())->not->toContain('SQLSTATE');
});
