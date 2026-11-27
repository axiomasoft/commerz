<?php

declare(strict_types=1);

use Commerz\B2b\B2bServiceProvider;
use Commerz\Identity\Uuid7;
use Commerz\Schema\TableNames;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Data\Compiler\ConstraintNames;

uses(RefreshDatabase::class);

const B2B_TABLES = [
    'b2b_business_units',
    'b2b_associates',
    'b2b_payment_terms',
    'b2b_credit_accounts',
    'b2b_credit_holds',
    'b2b_approval_rules',
    'b2b_approval_flows',
    'b2b_quote_requests',
    'b2b_staged_quotes',
    'b2b_negotiated_quotes',
];

function b2bTable(string $logical): string
{
    return app(TableNames::class)->table($logical);
}

/** @return list<string> */
function b2bConstraints(string $logical): array
{
    /** @var list<object{conname: string}> $rows */
    $rows = DB::select("select conname from pg_constraint where conrelid = ?::regclass and contype <> 'n' order by conname", [b2bTable($logical)]);
    $names = [];

    foreach ($rows as $row) {
        $names[] = $row->conname;
    }

    return $names;
}

it('declares the b2b noun under commerz.b2b', function (): void {
    $ownership = app(SchemaOwnership::class);

    expect($ownership->owners()['commerz.b2b'])->toBe(B2bServiceProvider::NOUNS)
        ->and($ownership->ownerOf('b2b'))->toBe('commerz.b2b');
});

it('creates every B2B table with canonical constraint names and enforces INV-B2B-CREDIT-001', function (): void {
    foreach (B2B_TABLES as $logical) {
        $physical = b2bTable($logical);

        expect(DB::getSchemaBuilder()->hasTable($physical))->toBeTrue("table {$physical}");
        expect(b2bConstraints($logical))->toContain(ConstraintNames::primary($physical));
    }

    $unitId = Uuid7::generate();
    DB::table(b2bTable('b2b_business_units'))->insert([
        'id' => $unitId,
        'code' => 'acme-pg',
        'name' => 'Acme Corp',
        'parent_id' => null,
        'root_id' => $unitId,
        'materialized_path' => '/'.$unitId.'/',
        'depth' => 0,
        'status' => 'active',
        'default_currency' => 'USD',
    ]);

    $creditId = Uuid7::generate();
    DB::table(b2bTable('b2b_credit_accounts'))->insert([
        'id' => $creditId,
        'business_unit_id' => $unitId,
        'currency' => 'USD',
        'status' => 'active',
        'credit_limit_minor' => 10_000,
        'used_credit_minor' => 6_000,
        'reserved_credit_minor' => 4_000,
        'version' => 1,
    ]);

    expect(fn () => DB::transaction(static fn () => DB::table(b2bTable('b2b_credit_accounts'))
        ->where('id', $creditId)
        ->update(['reserved_credit_minor' => 4_001])))->toThrow(QueryException::class, 'inv_b2b_credit_001');
});
