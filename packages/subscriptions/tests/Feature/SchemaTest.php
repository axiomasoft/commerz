<?php

declare(strict_types=1);

use Commerz\Schema\TableNames;
use Commerz\Subscriptions\SubscriptionsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Data\Compiler\ConstraintNames;

uses(RefreshDatabase::class);

const SUBSCRIPTIONS_TABLES = [
    'subscription_contracts',
    'subscription_contract_lines',
    'subscription_billing_cycles',
    'subscription_renewal_orders',
    'subscription_processed_events',
];

function subTable(string $logical): string
{
    return app(TableNames::class)->table($logical);
}

/** @return list<string> */
function subConstraints(string $logical): array
{
    /** @var list<object{conname: string}> $rows */
    $rows = DB::select("select conname from pg_constraint where conrelid = ?::regclass and contype <> 'n' order by conname", [subTable($logical)]);
    $names = [];

    foreach ($rows as $row) {
        $names[] = $row->conname;
    }

    return $names;
}

it('declares the subscription noun under commerz.subscriptions', function (): void {
    $ownership = app(SchemaOwnership::class);

    expect($ownership->owners()['commerz.subscriptions'])->toBe(SubscriptionsServiceProvider::NOUNS)
        ->and($ownership->ownerOf('subscription_contracts'))->toBe('commerz.subscriptions')
        ->and($ownership->ownerOf('subscription_billing_cycles'))->toBe('commerz.subscriptions');
});

it('creates every subscriptions table with canonical constraint names', function (): void {
    foreach (SUBSCRIPTIONS_TABLES as $logical) {
        $physical = subTable($logical);

        expect(DB::getSchemaBuilder()->hasTable($physical))->toBeTrue("table {$physical}");
        expect(subConstraints($logical))->toContain(ConstraintNames::primary($physical));
    }
});
