<?php

declare(strict_types=1);

use Commerz\Facades\Commerz;
use Commerz\Money\Currency;
use Commerz\Stores\Data\CreateStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('keeps the application booted in reverse order inside the test transaction', function (): void {
    $this->bootInReverseOrder();

    Commerz::stores()->create(new CreateStore('reverse', 'Reverse', Currency::of('RUB')));

    expect(DB::connection()->getPdo()->inTransaction())->toBeTrue();
});

it('leaves no row of the reverse boot behind', function (): void {
    expect(DB::table('cmz_stores')->where('code', 'reverse')->exists())->toBeFalse();
});
