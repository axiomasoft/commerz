<?php

declare(strict_types=1);

use Commerz\Identity\Uuid7;
use Commerz\Promotions\PromotionsServiceProvider;
use Commerz\Schema\ConstraintNames;
use Commerz\Schema\Contracts\SchemaOwnership;
use Commerz\Schema\TableNames;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const PROMOTIONS_TABLES = [
    'promotions', 'promotion_channels', 'promotion_rules', 'promotion_customer_usages',
    'coupon_batches', 'coupons', 'promotion_redemptions', 'promotion_usages',
];

function prmTable(string $logical): string
{
    return app(TableNames::class)->table($logical);
}

/** @return list<string> */
function prmConstraints(string $logical): array
{
    return array_map(
        static fn (object $row): string => $row->conname,
        DB::select('select conname from pg_constraint where conrelid = ?::regclass order by conname', [prmTable($logical)]),
    );
}

// RefreshDatabase wraps each test in a transaction; a savepoint keeps it usable after an expected violation.

/** @param  array<string, mixed>  $row */
function prmInsert(string $logical, array $row): void
{
    DB::transaction(static fn () => DB::table(prmTable($logical))->insert($row));
}

/** @param  array<string, mixed>  $set */
function prmUpdate(string $logical, string $id, array $set): void
{
    DB::transaction(static fn () => DB::table(prmTable($logical))->where('id', $id)->update($set));
}

function prmDelete(string $logical, string $id): void
{
    DB::transaction(static fn () => DB::table(prmTable($logical))->where('id', $id)->delete());
}

/** The SQLSTATE a statement fails with, or null when it succeeds. */
function prmSqlState(Closure $statement): ?string
{
    try {
        $statement();
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? null;
    }

    return null;
}

/** @param  array<string, mixed>  $extra */
function prmPromotion(string $code = 'autumn', array $extra = []): string
{
    $id = Uuid7::generate();

    prmInsert('promotions', ['id' => $id, 'code' => $code, 'name' => ucfirst($code), 'stage' => 'cart', 'activator' => 'automatic', 'stacking' => 'stackable', ...$extra]);

    return $id;
}

/** @param  array<string, mixed>  $extra */
function prmCoupon(string $promotion, string $code = 'SAVE10', array $extra = []): string
{
    $id = Uuid7::generate();

    prmInsert('coupons', ['id' => $id, 'promotion_id' => $promotion, 'code' => $code, ...$extra]);

    return $id;
}

/** @param  array<string, mixed>  $extra */
function prmRedemption(string $order = 'order:1', array $extra = []): string
{
    $id = Uuid7::generate();

    prmInsert('promotion_redemptions', [
        'id' => $id, 'order_ref' => $order, 'fingerprint' => str_repeat('a', 64), 'state' => 'consumed', 'consumed_at' => '2026-10-12 10:00:00+00', ...$extra,
    ]);

    return $id;
}

it('declares the promotion and coupon nouns under commerz.promotions', function (): void {
    $ownership = app(SchemaOwnership::class);

    expect($ownership->owners()['commerz.promotions'])->toBe(PromotionsServiceProvider::NOUNS)
        ->and($ownership->ownerOf('promotion'))->toBe('commerz.promotions')
        ->and($ownership->ownerOf('coupon'))->toBe('commerz.promotions');
});

it('creates every table with canonical constraint names', function (): void {
    foreach (PROMOTIONS_TABLES as $logical) {
        $physical = prmTable($logical);

        expect(DB::getSchemaBuilder()->hasTable($physical))->toBeTrue("table {$physical}");

        foreach (prmConstraints($logical) as $name) {
            expect($name)->toMatch('/(_(pk|uq|fk|ck|ex)|_[0-9a-f]{8})\z/'); // a name over 63 bytes ends in the hash of ConstraintNames::shorten
        }

        foreach (DB::select('select indexname from pg_indexes where tablename = ?', [$physical]) as $index) {
            expect($index->indexname)->toMatch('/(_(pk|uq|ix|ex)|_[0-9a-f]{8})\z/');
        }

        expect(prmConstraints($logical))->toContain(ConstraintNames::primary($physical));
    }
});

it('keeps money and counters in bigint and integer, ids as uuid and rule parameters as jsonb', function (): void {
    $type = static fn (string $logical, string $column): string => DB::selectOne(
        'select data_type from information_schema.columns where table_name = ? and column_name = ?',
        [prmTable($logical), $column],
    )->data_type;

    expect($type('promotions', 'budget_minor'))->toBe('bigint')
        ->and($type('promotions', 'spent_minor'))->toBe('bigint')
        ->and($type('promotions', 'used'))->toBe('integer')
        ->and($type('promotions', 'priority'))->toBe('integer')
        ->and($type('promotions', 'starts_at'))->toBe('timestamp with time zone')
        ->and($type('promotion_channels', 'channel_id'))->toBe('uuid')
        ->and($type('promotion_rules', 'id'))->toBe('bigint')
        ->and($type('promotion_rules', 'config'))->toBe('jsonb')
        ->and($type('coupons', 'used'))->toBe('integer')
        ->and($type('promotion_usages', 'discount_minor'))->toBe('bigint')
        ->and($type('promotion_usages', 'id'))->toBe('bigint')
        ->and($type('promotion_redemptions', 'order_ref'))->toBe('character varying');
});

it('has no foreign key to the core or other packages', function (): void {
    $prefix = substr(prmTable('promotions'), 0, -strlen('promotions'));

    foreach (PROMOTIONS_TABLES as $logical) {
        $targets = DB::select(
            "select confrelid::regclass::text as target from pg_constraint where conrelid = ?::regclass and contype = 'f'",
            [prmTable($logical)],
        );

        foreach ($targets as $row) {
            expect($row->target)->toMatch('/\A'.preg_quote($prefix, '/').'(promotion|coupon)/', "{$logical} references {$row->target}");
        }
    }
});

it('keeps promotion codes unique and checks the enumerations, with defaults', function (): void {
    $id = prmPromotion();

    expect(fn () => prmPromotion('autumn'))->toThrow(UniqueConstraintViolationException::class, 'cmz_promotions_code_uq')
        ->and(fn () => prmUpdate('promotions', $id, ['stage' => 'checkout']))->toThrow(QueryException::class, 'cmz_promotions_stage_valid_ck')
        ->and(fn () => prmUpdate('promotions', $id, ['stacking' => 'maybe']))->toThrow(QueryException::class, 'cmz_promotions_stacking_valid_ck')
        ->and(fn () => prmUpdate('promotions', $id, ['status' => 'deleted']))->toThrow(QueryException::class, 'cmz_promotions_status_valid_ck');

    $row = DB::table(prmTable('promotions'))->where('id', $id)->first();

    expect($row->status)->toBe('active')
        ->and($row->stop_further)->toBeFalse()
        ->and($row->priority)->toBe(0)
        ->and($row->used)->toBe(0)
        ->and($row->spent_minor)->toBe(0)
        ->and($row->revision)->toBe(1)
        ->and($row->usage_limit)->toBeNull()
        ->and($row->budget_minor)->toBeNull()
        ->and($row->currency)->toBeNull();

    foreach (['catalog', 'cart', 'delivery'] as $stage) {
        expect(prmSqlState(static fn () => prmUpdate('promotions', $id, ['stage' => $stage])))->toBeNull();
    }
});

it('checks the window, the limits, the counters and the budget of a promotion (PRM-01)', function (): void {
    $id = prmPromotion();
    $update = static fn (array $set): Closure => static fn () => prmUpdate('promotions', $id, $set);

    expect($update(['starts_at' => '2026-02-01', 'ends_at' => '2026-02-01']))->toThrow(QueryException::class, 'cmz_promotions_window_ordered_ck')
        ->and($update(['usage_limit' => 0]))->toThrow(QueryException::class, 'cmz_promotions_usage_limit_positive_ck')
        ->and($update(['per_customer_limit' => 0]))->toThrow(QueryException::class, 'cmz_promotions_per_customer_limit_positive_ck')
        ->and($update(['used' => -1]))->toThrow(QueryException::class, 'cmz_promotions_used_non_negative_ck')
        ->and($update(['spent_minor' => -1]))->toThrow(QueryException::class, 'cmz_promotions_spent_non_negative_ck')
        ->and($update(['budget_minor' => 0, 'currency' => 'RUB']))->toThrow(QueryException::class, 'cmz_promotions_budget_positive_ck')
        ->and($update(['budget_minor' => 1000]))->toThrow(QueryException::class, 'cmz_promotions_budget_has_currency_ck')
        ->and(prmSqlState($update(['starts_at' => '2026-02-01', 'ends_at' => '2026-03-01', 'usage_limit' => 5, 'per_customer_limit' => 1, 'budget_minor' => 1000, 'currency' => 'RUB'])))->toBeNull()
        ->and(prmSqlState($update(['starts_at' => null, 'ends_at' => '2026-03-01'])))->toBeNull();
});

it('assigns a promotion to a channel once and removes the assignment with the promotion', function (): void {
    $id = prmPromotion();
    $channel = Uuid7::generate(); // not a real channel: assignments carry no foreign key to the core table
    prmInsert('promotion_channels', ['promotion_id' => $id, 'channel_id' => $channel]);

    expect(fn () => prmInsert('promotion_channels', ['promotion_id' => $id, 'channel_id' => $channel]))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => prmInsert('promotion_channels', ['promotion_id' => Uuid7::generate(), 'channel_id' => $channel]))->toThrow(QueryException::class, 'cmz_promotion_channels_promotion_id_fk');

    prmDelete('promotions', $id);

    expect(DB::table(prmTable('promotion_channels'))->count())->toBe(0);
});

it('keeps one rule per promotion, role and position and checks role and version', function (): void {
    $id = prmPromotion();
    $rule = static fn (array $extra = []): array => ['promotion_id' => $id, 'role' => 'effect', 'position' => 0, 'rule_key' => 'order.percent_off', 'config_version' => 1, 'config' => '{"rate_bp":1000}', ...$extra];
    prmInsert('promotion_rules', $rule());

    expect(fn () => prmInsert('promotion_rules', $rule()))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => prmInsert('promotion_rules', $rule(['role' => 'trigger', 'position' => 1])))->toThrow(QueryException::class, 'cmz_promotion_rules_role_valid_ck')
        ->and(fn () => prmInsert('promotion_rules', $rule(['position' => -1])))->toThrow(QueryException::class, 'cmz_promotion_rules_position_non_negative_ck')
        ->and(fn () => prmInsert('promotion_rules', $rule(['position' => 1, 'config_version' => 0])))->toThrow(QueryException::class, 'cmz_promotion_rules_config_version_positive_ck')
        ->and(fn () => prmInsert('promotion_rules', $rule(['position' => 1, 'config' => 'not json'])))->toThrow(QueryException::class)
        ->and(prmSqlState(static fn () => prmInsert('promotion_rules', $rule(['role' => 'condition']))))->toBeNull() // the same position in another role
        ->and(prmSqlState(static fn () => prmInsert('promotion_rules', $rule(['position' => 1]))))->toBeNull();

    $stored = DB::table(prmTable('promotion_rules'))->where('promotion_id', $id)->where('role', 'effect')->where('position', 0)->first();
    expect(json_decode($stored->config, true))->toBe(['rate_bp' => 1000]);

    prmDelete('promotions', $id);
    expect(DB::table(prmTable('promotion_rules'))->count())->toBe(0);
});

it('counts the usage of a customer once per promotion and never below zero', function (): void {
    $id = prmPromotion();
    prmInsert('promotion_customer_usages', ['promotion_id' => $id, 'customer_ref' => 'customer:7', 'used' => 1]);

    expect(fn () => prmInsert('promotion_customer_usages', ['promotion_id' => $id, 'customer_ref' => 'customer:7', 'used' => 1]))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => prmInsert('promotion_customer_usages', ['promotion_id' => $id, 'customer_ref' => 'customer:8', 'used' => -1]))->toThrow(QueryException::class, 'cmz_promotion_customer_usages_used_non_negative_ck')
        ->and(fn () => prmInsert('promotion_customer_usages', ['promotion_id' => Uuid7::generate(), 'customer_ref' => 'customer:7', 'used' => 1]))->toThrow(QueryException::class, 'cmz_promotion_customer_usages_promotion_id_fk');
});

it('stores only canonical coupon codes, once per installation (PRM-03)', function (): void {
    $id = prmPromotion();
    prmCoupon($id, 'SAVE10');

    expect(fn () => prmCoupon($id, 'SAVE10'))->toThrow(UniqueConstraintViolationException::class, 'cmz_coupons_code_uq')
        ->and(fn () => prmCoupon($id, 'autumn'))->toThrow(QueryException::class, 'cmz_coupons_code_canonical_ck')
        ->and(fn () => prmCoupon($id, 'Save10'))->toThrow(QueryException::class, 'cmz_coupons_code_canonical_ck')
        ->and(fn () => prmCoupon($id, ' SAVE20'))->toThrow(QueryException::class, 'cmz_coupons_code_canonical_ck')
        ->and(fn () => prmCoupon($id, '-SAVE'))->toThrow(QueryException::class, 'cmz_coupons_code_canonical_ck')
        ->and(fn () => prmCoupon($id, str_repeat('A', 65)))->toThrow(QueryException::class)
        ->and(prmSqlState(static fn () => prmCoupon($id, 'A_B-9')))->toBeNull()
        ->and(prmSqlState(static fn () => prmCoupon($id, str_repeat('A', 64))))->toBeNull();
});

it('checks the coupon status, limit and counter, defaults them and refuses to orphan a coupon', function (): void {
    $promotion = prmPromotion();
    $coupon = prmCoupon($promotion);
    $update = static fn (array $set): Closure => static fn () => prmUpdate('coupons', $coupon, $set);

    expect($update(['status' => 'deleted']))->toThrow(QueryException::class, 'cmz_coupons_status_valid_ck')
        ->and($update(['usage_limit' => 0]))->toThrow(QueryException::class, 'cmz_coupons_usage_limit_positive_ck')
        ->and($update(['used' => -1]))->toThrow(QueryException::class, 'cmz_coupons_used_non_negative_ck')
        ->and(fn () => prmCoupon(Uuid7::generate(), 'OTHER'))->toThrow(QueryException::class, 'cmz_coupons_promotion_id_fk')
        ->and(fn () => prmDelete('promotions', $promotion))->toThrow(QueryException::class, 'cmz_coupons_promotion_id_fk');

    $row = DB::table(prmTable('coupons'))->where('id', $coupon)->first();
    expect($row->status)->toBe('active')->and($row->used)->toBe(0)->and($row->usage_limit)->toBeNull()->and($row->batch_id)->toBeNull();
});

it('ties a coupon to its generation batch', function (): void {
    $promotion = prmPromotion();
    $batch = Uuid7::generate();
    prmInsert('coupon_batches', ['id' => $batch, 'promotion_id' => $promotion, 'pattern' => 'AUTUMN-####', 'count' => 100]);

    expect(fn () => prmInsert('coupon_batches', ['id' => Uuid7::generate(), 'promotion_id' => $promotion, 'pattern' => 'X', 'count' => 0]))->toThrow(QueryException::class, 'cmz_coupon_batches_count_positive_ck')
        ->and(fn () => prmInsert('coupon_batches', ['id' => Uuid7::generate(), 'promotion_id' => $promotion, 'pattern' => 'X', 'count' => 1, 'usage_limit' => 0]))->toThrow(QueryException::class, 'cmz_coupon_batches_usage_limit_positive_ck')
        ->and(fn () => prmCoupon($promotion, 'LOST-0001', ['batch_id' => Uuid7::generate()]))->toThrow(QueryException::class, 'cmz_coupons_batch_id_fk');

    prmCoupon($promotion, 'AUTUMN-0001', ['batch_id' => $batch]);

    expect(fn () => prmDelete('coupon_batches', $batch))->toThrow(QueryException::class, 'cmz_coupons_batch_id_fk');
});

it('keeps one redemption per order and a consistent restored state (PRM-01)', function (): void {
    prmRedemption('order:1');

    $restored = ['state' => 'restored', 'restore_reason' => 'cancelled', 'restored_at' => '2026-10-12 11:00:00+00'];

    expect(fn () => prmRedemption('order:1'))->toThrow(UniqueConstraintViolationException::class, 'cmz_promotion_redemptions_order_ref_uq')
        ->and(fn () => prmRedemption('order:2', ['state' => 'pending']))->toThrow(QueryException::class, 'cmz_promotion_redemptions_state_valid_ck')
        ->and(fn () => prmRedemption('order:2', ['state' => 'restored']))->toThrow(QueryException::class, 'cmz_promotion_redemptions_restored_consistent_ck')
        ->and(fn () => prmRedemption('order:2', ['restored_at' => '2026-10-12 11:00:00+00']))->toThrow(QueryException::class, 'cmz_promotion_redemptions_restored_consistent_ck')
        ->and(fn () => prmRedemption('order:2', [...$restored, 'restore_reason' => 'lost']))->toThrow(QueryException::class, 'cmz_promotion_redemptions_restore_reason_valid_ck')
        ->and(fn () => prmRedemption('order:2', ['fingerprint' => str_repeat('a', 65)]))->toThrow(QueryException::class)
        ->and(prmSqlState(static fn () => prmRedemption('order:3', $restored)))->toBeNull()
        ->and(prmSqlState(static fn () => prmRedemption('order:4', ['customer_ref' => 'customer:7'])))->toBeNull();
});

it('records one usage per promotion on a redemption and protects the promotion row', function (): void {
    $promotion = prmPromotion();
    $coupon = prmCoupon($promotion);
    $redemption = prmRedemption();
    $usage = static fn (array $extra = []): array => ['redemption_id' => $redemption, 'promotion_id' => $promotion, 'discount_minor' => 500, 'currency' => 'RUB', ...$extra];
    prmInsert('promotion_usages', $usage(['coupon_id' => $coupon]));
    $plain = prmPromotion('plain'); // no coupon, so only a usage holds it
    prmInsert('promotion_usages', $usage(['promotion_id' => $plain]));

    expect(fn () => prmInsert('promotion_usages', $usage()))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => prmInsert('promotion_usages', $usage(['promotion_id' => Uuid7::generate()])))->toThrow(QueryException::class, 'cmz_promotion_usages_promotion_id_fk')
        ->and(fn () => prmInsert('promotion_usages', $usage(['coupon_id' => Uuid7::generate(), 'promotion_id' => prmPromotion('winter')])))->toThrow(QueryException::class, 'cmz_promotion_usages_coupon_id_fk')
        ->and(fn () => prmInsert('promotion_usages', $usage(['promotion_id' => prmPromotion('spring'), 'discount_minor' => -1])))->toThrow(QueryException::class, 'cmz_promotion_usages_discount_non_negative_ck')
        ->and(fn () => prmDelete('promotions', $plain))->toThrow(QueryException::class, 'cmz_promotion_usages_promotion_id_fk')
        ->and(fn () => prmDelete('coupons', $coupon))->toThrow(QueryException::class, 'cmz_promotion_usages_coupon_id_fk');

    prmDelete('promotion_redemptions', $redemption);

    expect(DB::table(prmTable('promotion_usages'))->count())->toBe(0);
});
