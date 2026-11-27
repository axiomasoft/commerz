<?php

declare(strict_types=1);

use Commerz\Identity\Uuid7;
use Commerz\Schema\TableNames;
use Commerz\StoredValue\StoredValueServiceProvider;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Data\Compiler\ConstraintNames;

uses(RefreshDatabase::class);

const STORED_VALUE_TABLES = [
    'stored_value_accounts',
    'stored_value_holds',
    'stored_value_ledger',
];

function svTable(string $logical): string
{
    return app(TableNames::class)->table($logical);
}

/** @return list<string> */
function svConstraints(string $logical): array
{
    /** @var list<object{conname: string}> $rows */
    $rows = DB::select("select conname from pg_constraint where conrelid = ?::regclass and contype <> 'n' order by conname", [svTable($logical)]);
    $names = [];

    foreach ($rows as $row) {
        $names[] = $row->conname;
    }

    return $names;
}

it('declares the stored_value noun under commerz.stored-value', function (): void {
    $ownership = app(SchemaOwnership::class);

    expect($ownership->owners()['commerz.stored-value'])->toBe(StoredValueServiceProvider::NOUNS)
        ->and($ownership->ownerOf('stored_value'))->toBe('commerz.stored-value');
});

it('creates every stored-value table with canonical constraint names and enforces INV-SV-001 and ledger immutability', function (): void {
    foreach (STORED_VALUE_TABLES as $logical) {
        $physical = svTable($logical);

        expect(DB::getSchemaBuilder()->hasTable($physical))->toBeTrue("table {$physical}");
        expect(svConstraints($logical))->toContain(ConstraintNames::primary($physical));
    }

    $accountId = Uuid7::generate();
    $codeHash = hash('sha256', 'GC1234567890');

    DB::table(svTable('stored_value_accounts'))->insert([
        'id' => $accountId,
        'kind' => 'gift_card',
        'status' => 'active',
        'currency' => 'USD',
        'code_hash' => $codeHash,
        'code_last4' => '7890',
        'initial_minor' => 5000,
        'balance_minor' => 5000,
        'held_minor' => 0,
        'version' => 0,
    ]);

    // INV-SV-001 check constraint rejects held_minor > balance_minor
    expect(fn () => DB::transaction(static fn () => DB::table(svTable('stored_value_accounts'))
        ->where('id', $accountId)
        ->update(['held_minor' => 5001])))->toThrow(QueryException::class, 'inv_sv_001');

    // Duplicate gift card code_hash rejected
    expect(fn () => DB::transaction(static fn () => DB::table(svTable('stored_value_accounts'))->insert([
        'id' => Uuid7::generate(),
        'kind' => 'gift_card',
        'status' => 'active',
        'currency' => 'USD',
        'code_hash' => $codeHash,
        'code_last4' => '7890',
        'initial_minor' => 1000,
        'balance_minor' => 1000,
        'held_minor' => 0,
        'version' => 0,
    ])))->toThrow(UniqueConstraintViolationException::class);

    // Ledger immutability trigger rejects UPDATE and DELETE
    $journalId = Uuid7::generate();
    DB::table(svTable('stored_value_ledger'))->insert([
        'journal_id' => $journalId,
        'account_id' => $accountId,
        'entry_type' => 'issue',
        'entry_side' => 'account',
        'direction' => 'credit',
        'ledger_account' => "liability:stored_value:{$accountId}",
        'currency' => 'USD',
        'amount_minor' => 5000,
        'balance_delta_minor' => 5000,
        'held_delta_minor' => 0,
        'balance_after_minor' => 5000,
        'held_after_minor' => 0,
    ]);

    expect(fn () => DB::transaction(static fn () => DB::table(svTable('stored_value_ledger'))
        ->where('journal_id', $journalId)
        ->update(['amount_minor' => 1])))->toThrow(QueryException::class, 'immutable')
        ->and(fn () => DB::transaction(static fn () => DB::table(svTable('stored_value_ledger'))
            ->where('journal_id', $journalId)
            ->delete()))->toThrow(QueryException::class, 'immutable');
});
