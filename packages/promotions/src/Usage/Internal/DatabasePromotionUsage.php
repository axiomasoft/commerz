<?php

declare(strict_types=1);

namespace Commerz\Promotions\Usage\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\IdempotencyConflict;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\Uuid7;
use Commerz\Money\Currency;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Operations\Fingerprint;
use Commerz\Ports\Promotions\Contracts\PromotionUsage;
use Commerz\Ports\Promotions\Data\AppliedPromotion;
use Commerz\Ports\Promotions\Data\ConsumePromotionUsage;
use Commerz\Ports\Promotions\Data\RestorePromotionUsage;
use Commerz\Ports\Promotions\Exceptions\UsageLimitReached;
use Commerz\Promotions\Common\Data\PromotionCodes;
use Commerz\Promotions\Configuration\PromotionsOptions;
use Commerz\Promotions\Coupons\Enums\CouponStatus;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Commerz\Promotions\Definitions\Enums\PromotionStatus;
use Commerz\Promotions\Usage\Exceptions\PromotionUnavailable;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;
use RuntimeException;

/**
 * PRM-01, D27. Locks, in the global order (knowledge/invariants#placement): the order's redemption header, then
 * coupons, then promotions, then customer usages, each by id ASC. Every promotion and coupon of the order is taken
 * `for key share`, which only a change of its definition (`for update`) waits for; a counter row is updated (`no key
 * update`) only when its limit is set, so an unlimited promotion is never a row all placements queue on. Each limit is
 * enforced by the database in one conditional statement, never read and then written from PHP.
 */
#[Internal]
final readonly class DatabasePromotionUsage implements PromotionUsage
{
    private const string SCOPE = 'promotions.consume';

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private PromotionsOptions $promotions,
        private TableNames $tables,
        private Transactions $transactions,
    ) {}

    public function consume(ConsumePromotionUsage $command): void
    {
        if ($command->applied === []) {
            return;
        }

        [$applied, $currency] = $this->normalized($command->applied);
        $customer = $command->customer === null ? null : (string) $command->customer;
        $fingerprint = Fingerprint::of([
            'customer' => $customer,
            'applied' => array_map(static fn (AppliedPromotion $a): array => [
                $a->promotionId, $a->couponCode, $a->discount->amountMinor, $a->discount->currency->code,
            ], $applied),
        ]);

        $this->transactions->run(function () use ($command, $applied, $currency, $customer, $fingerprint): void {
            $redemption = $this->open($command->orderRef, $customer, $fingerprint);

            if ($redemption === null) {
                return; // the same order was already counted with the same promotions
            }

            $coupons = $this->consumeCoupons($applied);
            $promotions = $this->consumePromotions($applied, $currency);
            $this->consumeCustomers($applied, $promotions, $customer);
            $this->record($redemption, $applied, $coupons);
        });
    }

    public function restore(RestorePromotionUsage $command): void
    {
        if (! $this->promotions->restores($command->reason)) {
            return;
        }

        $this->transactions->run(function () use ($command): void {
            $db = $this->db();
            $redemptions = $this->tables->table('promotion_redemptions');
            $redemption = $db->table($redemptions)
                ->where('order_ref', $command->orderRef)
                ->lock('for no key update')
                ->first(['id', 'state', 'customer_ref']);

            if ($redemption === null || Rows::string($redemption, 'state') !== 'consumed') {
                return; // never consumed, or already given back
            }

            $id = Rows::string($redemption, 'id');
            $usages = $db->table($this->tables->table('promotion_usages'))
                ->where('redemption_id', $id)
                ->get(['promotion_id', 'coupon_id', 'discount_minor'])
                ->all();
            $coupons = [];

            foreach ($usages as $usage) {
                $coupon = Rows::nullableString($usage, 'coupon_id');

                if ($coupon !== null) {
                    $coupons[] = $coupon;
                }
            }

            $this->restoreCoupons($coupons);
            $this->restorePromotions($usages, Rows::nullableString($redemption, 'customer_ref'));

            $db->table($redemptions)->where('id', $id)->update([
                'state' => 'restored',
                'restore_reason' => $command->reason->value,
                'restored_at' => PgTimestamp::now(),
            ]);
        });
    }

    /**
     * Shape checks before anything is locked: ids are UUIDs and unique, one currency, coupon codes in canonical form.
     *
     * @param  list<AppliedPromotion>  $applied
     * @return array{list<AppliedPromotion>, Currency} sorted by promotion id
     */
    private function normalized(array $applied): array
    {
        $byId = [];
        $currency = $applied[0]->discount->currency;

        foreach ($applied as $index => $promotion) {
            try {
                $id = new PromotionId($promotion->promotionId)->value;
            } catch (InvalidCommand) {
                throw new InvalidCommand("applied.{$index}.promotionId", "'{$promotion->promotionId}' is not a UUID", 'promotions.invalid_usage');
            }

            if (isset($byId[$id])) {
                throw new InvalidCommand("applied.{$index}.promotionId", "promotion '{$id}' is listed twice", 'promotions.invalid_usage');
            }

            if (! $promotion->discount->currency->equals($currency)) {
                throw new InvalidCommand("applied.{$index}.discount", "expected {$currency->code} like the other discounts", 'promotions.invalid_usage');
            }

            $code = $promotion->couponCode;

            if ($code !== null) {
                try {
                    $code = PromotionCodes::couponCode("applied.{$index}.couponCode", $code);
                } catch (InvalidCommand) {
                    // Not a code any coupon can have: the lookup below reports coupon_not_found.
                }
            }

            $byId[$id] = new AppliedPromotion($id, $code, $promotion->discount);
        }

        ksort($byId, SORT_STRING);

        return [array_values($byId), $currency];
    }

    /**
     * Step 1: the redemption header, unique per order. A concurrent consume of the same order waits on the unique
     * index until the first commits and then compares the fingerprint.
     *
     * @return string|null the new redemption id; null for a repeat with the same payload
     *
     * @throws IdempotencyConflict the order was counted with other promotions, amounts or customer
     */
    private function open(string $orderRef, ?string $customer, string $fingerprint): ?string
    {
        $db = $this->db();
        $table = $this->tables->table('promotion_redemptions');
        $id = Uuid7::generate();

        $inserted = $db->select(
            "insert into {$this->wrap($table)} (id, order_ref, customer_ref, fingerprint, state, consumed_at)"
            ." values (?, ?, ?, ?, 'consumed', ?) on conflict (order_ref) do nothing returning id",
            [$id, $orderRef, $customer, $fingerprint, PgTimestamp::now()],
        );

        if ($inserted !== []) {
            return $id;
        }

        $existing = $db->table($table)->where('order_ref', $orderRef)->first(['fingerprint'])
            ?? throw new RuntimeException("Redemption of '{$orderRef}' conflicted but cannot be read.");

        if (Rows::string($existing, 'fingerprint') === $fingerprint) {
            return null;
        }

        throw new IdempotencyConflict(self::SCOPE, $orderRef);
    }

    /**
     * Step 2: coupons of the order `for key share` by id, checked, then the limited ones counted by id.
     *
     * @param  list<AppliedPromotion>  $applied
     * @return array<string, string> coupon id by promotion id
     */
    private function consumeCoupons(array $applied): array
    {
        $codes = [];

        foreach ($applied as $promotion) {
            if ($promotion->couponCode !== null) {
                $codes[] = $promotion->couponCode;
            }
        }

        if ($codes === []) {
            return [];
        }

        $db = $this->db();
        $table = $this->tables->table('coupons');
        $rows = $db->table($table)
            ->whereIn('code', $codes)
            ->orderBy('id')
            ->lock('for key share')
            ->get(['id', 'promotion_id', 'code', 'status', 'usage_limit'])
            ->all();

        $byCode = [];

        foreach ($rows as $row) {
            $byCode[Rows::string($row, 'code')] = $row;
        }

        $ids = [];

        foreach ($applied as $promotion) {
            if ($promotion->couponCode === null) {
                continue;
            }

            $row = $byCode[$promotion->couponCode] ?? null;

            if ($row === null || Rows::string($row, 'promotion_id') !== $promotion->promotionId) {
                throw new PromotionUnavailable($promotion->promotionId, $promotion->couponCode, PromotionUnavailable::COUPON_NOT_FOUND);
            }

            if (Rows::string($row, 'status') !== CouponStatus::Active->value) {
                throw new PromotionUnavailable($promotion->promotionId, $promotion->couponCode, PromotionUnavailable::COUPON_DISABLED);
            }

            $ids[$promotion->promotionId] = Rows::string($row, 'id');
        }

        $now = PgTimestamp::now();

        foreach ($rows as $row) {
            if (Rows::nullableInt($row, 'usage_limit') === null) {
                continue;
            }

            $updated = $db->update(
                "update {$this->wrap($table)} set used = used + 1, updated_at = ? where id = ? and used < usage_limit",
                [$now, Rows::string($row, 'id')],
            );

            if ($updated === 0) {
                throw new UsageLimitReached(Rows::string($row, 'promotion_id'), Rows::string($row, 'code'), 'coupon');
            }
        }

        return $ids;
    }

    /**
     * Step 3: promotions of the order `for key share` by id, checked (status, not the window: the quote's time is the
     * authority), then the ones with a total limit or a budget counted by id in one conditional update each.
     *
     * @param  list<AppliedPromotion>  $applied  sorted by promotion id
     * @return array<string, object> promotion rows by id
     */
    private function consumePromotions(array $applied, Currency $currency): array
    {
        $db = $this->db();
        $table = $this->tables->table('promotions');
        $rows = [];

        foreach ($db->table($table)
            ->whereIn('id', array_map(static fn (AppliedPromotion $a): string => $a->promotionId, $applied))
            ->orderBy('id')
            ->lock('for key share')
            ->get(['id', 'status', 'currency', 'usage_limit', 'per_customer_limit', 'budget_minor'])
            ->all() as $row) {
            $rows[Rows::string($row, 'id')] = $row;
        }

        foreach ($applied as $promotion) {
            $row = $rows[$promotion->promotionId]
                ?? throw new PromotionUnavailable($promotion->promotionId, $promotion->couponCode, PromotionUnavailable::PROMOTION_NOT_FOUND);

            if (Rows::string($row, 'status') !== PromotionStatus::Active->value) {
                throw new PromotionUnavailable($promotion->promotionId, $promotion->couponCode, PromotionUnavailable::PROMOTION_NOT_ACTIVE);
            }

            $own = Rows::nullableString($row, 'currency');

            if ($own !== null && $own !== $currency->code) {
                throw new InvalidCommand('applied', "promotion '{$promotion->promotionId}' is in {$own}, the discounts in {$currency->code}", 'promotions.currency_mismatch');
            }
        }

        $now = PgTimestamp::now();

        foreach ($applied as $promotion) {
            $row = $rows[$promotion->promotionId];

            if (Rows::nullableInt($row, 'usage_limit') === null && Rows::nullableInt($row, 'budget_minor') === null) {
                continue;
            }

            $discount = $promotion->discount->amountMinor;
            $updated = $db->update(
                "update {$this->wrap($table)} set used = used + case when usage_limit is null then 0 else 1 end,"
                .' spent_minor = spent_minor + case when budget_minor is null then 0 else ? end, updated_at = ?'
                .' where id = ? and (usage_limit is null or used < usage_limit) and (budget_minor is null or ? <= budget_minor - spent_minor)',
                [$discount, $now, $promotion->promotionId, $discount],
            );

            if ($updated === 0) {
                $current = $db->table($table)->where('id', $promotion->promotionId)->first(['used', 'usage_limit'])
                    ?? throw new RuntimeException("Promotion '{$promotion->promotionId}' is missing under its lock.");
                $limit = Rows::nullableInt($current, 'usage_limit');

                throw new UsageLimitReached(
                    $promotion->promotionId,
                    $promotion->couponCode,
                    $limit !== null && Rows::int($current, 'used') >= $limit ? 'total' : 'budget',
                );
            }
        }

        return $rows;
    }

    /**
     * Step 4: one order more for the customer of each promotion with a per-customer limit, by promotion id. A guest
     * cannot use such a promotion (fail-closed, D27).
     *
     * @param  list<AppliedPromotion>  $applied  sorted by promotion id
     * @param  array<string, object>  $promotions  rows by id
     */
    private function consumeCustomers(array $applied, array $promotions, ?string $customer): void
    {
        $table = $this->wrap($this->tables->table('promotion_customer_usages'));
        $now = PgTimestamp::now();

        foreach ($applied as $promotion) {
            $limit = Rows::nullableInt($promotions[$promotion->promotionId], 'per_customer_limit');

            if ($limit === null) {
                continue;
            }

            if ($customer === null) {
                throw new UsageLimitReached($promotion->promotionId, $promotion->couponCode, 'per_customer');
            }

            $counted = $this->db()->select(
                "insert into {$table} (promotion_id, customer_ref, used, updated_at) values (?, ?, 1, ?)"
                ." on conflict (promotion_id, customer_ref) do update set used = {$table}.used + 1, updated_at = excluded.updated_at"
                ." where {$table}.used < ? returning used",
                [$promotion->promotionId, $customer, $now, $limit],
            );

            if ($counted === []) {
                throw new UsageLimitReached($promotion->promotionId, $promotion->couponCode, 'per_customer');
            }
        }
    }

    /**
     * Step 5: the usage log, one row per applied promotion; the source the counters are recomputed and reconciled from.
     *
     * @param  list<AppliedPromotion>  $applied
     * @param  array<string, string>  $coupons  coupon id by promotion id
     */
    private function record(string $redemption, array $applied, array $coupons): void
    {
        $now = PgTimestamp::now();

        $this->db()->table($this->tables->table('promotion_usages'))->insert(array_map(
            static fn (AppliedPromotion $a): array => [
                'redemption_id' => $redemption,
                'promotion_id' => $a->promotionId,
                'coupon_id' => $coupons[$a->promotionId] ?? null,
                'discount_minor' => $a->discount->amountMinor,
                'currency' => $a->discount->currency->code,
                'created_at' => $now,
            ],
            $applied,
        ));
    }

    /** @param  list<string>  $ids */
    private function restoreCoupons(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $db = $this->db();
        $table = $this->tables->table('coupons');
        $now = PgTimestamp::now();

        foreach ($db->table($table)->whereIn('id', $ids)->orderBy('id')->lock('for key share')->get(['id', 'usage_limit'])->all() as $row) {
            if (Rows::nullableInt($row, 'usage_limit') !== null) {
                // greatest(): a counter recomputed meanwhile may already exclude this order; never below zero.
                $db->update(
                    "update {$this->wrap($table)} set used = greatest(used - 1, 0), updated_at = ? where id = ?",
                    [$now, Rows::string($row, 'id')],
                );
            }
        }
    }

    /** @param  array<array-key, object>  $usages  promotion_id and discount_minor of the redemption's usages */
    private function restorePromotions(array $usages, ?string $customer): void
    {
        $discounts = [];

        foreach ($usages as $usage) {
            $discounts[Rows::string($usage, 'promotion_id')] = Rows::int($usage, 'discount_minor');
        }

        if ($discounts === []) {
            return;
        }

        $db = $this->db();
        $table = $this->tables->table('promotions');
        $rows = $db->table($table)
            ->whereIn('id', array_keys($discounts))
            ->orderBy('id')
            ->lock('for key share')
            ->get(['id', 'usage_limit', 'budget_minor', 'per_customer_limit'])
            ->all();
        $now = PgTimestamp::now();

        foreach ($rows as $row) {
            if (Rows::nullableInt($row, 'usage_limit') === null && Rows::nullableInt($row, 'budget_minor') === null) {
                continue;
            }

            $db->update(
                "update {$this->wrap($table)} set used = case when usage_limit is null then used else greatest(used - 1, 0) end,"
                .' spent_minor = case when budget_minor is null then spent_minor else greatest(spent_minor - ?, 0) end, updated_at = ?'
                .' where id = ?',
                [$discounts[Rows::string($row, 'id')], $now, Rows::string($row, 'id')],
            );
        }

        if ($customer === null) {
            return;
        }

        $customers = $this->tables->table('promotion_customer_usages');

        foreach ($rows as $row) {
            if (Rows::nullableInt($row, 'per_customer_limit') !== null) {
                $db->update(
                    "update {$this->wrap($customers)} set used = greatest(used - 1, 0), updated_at = ? where promotion_id = ? and customer_ref = ?",
                    [$now, Rows::string($row, 'id'), $customer],
                );
            }
        }
    }

    private function wrap(string $table): string
    {
        return $this->db()->table($table)->getGrammar()->wrapTable($table);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
