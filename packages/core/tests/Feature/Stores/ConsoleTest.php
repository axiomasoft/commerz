<?php

declare(strict_types=1);

use Commerz\Configuration\CommerzOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('passes the doctor on a fresh install', function (): void {
    $exit = Artisan::call('packman:doctor', ['--prefix' => ['commerz']]);
    $output = Artisan::output();

    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('Configuration', 'fingerprint', 'plugins');
});

it('fails the doctor when the fingerprint row is gone', function (): void {
    DB::table('cmz_schema_state')->delete();

    $this->artisan('packman:doctor', ['--prefix' => ['commerz']])->expectsOutputToContain('FAIL')->assertExitCode(1);
});

it('fails the doctor on a broken configuration', function (): void {
    config()->set('commerz.table_prefix', 'BAD');
    app()->forgetInstance(CommerzOptions::class);

    $this->artisan('packman:doctor', ['--prefix' => ['commerz']])->expectsOutputToContain('FAIL')->assertExitCode(1);
});

it('installs without migrating on request', function (): void {
    $this->artisan('commerz:install', ['--no-migrate' => true])->assertExitCode(0);
});
