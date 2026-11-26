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
use Commerz\Promotions\Campaigns\Exceptions\CampaignArchived;
use Commerz\Promotions\Campaigns\Exceptions\CampaignBudgetExceeded;
use Commerz\Promotions\Campaigns\Exceptions\CampaignNotFound;
use Commerz\Promotions\Campaigns\Internal\CampaignBudgetLedger;
use Commerz\Promotions\Common\Data\PromotionCodes;
use Commerz\Promotions\Configuration\PromotionsOptions;
use Commerz\Promotions\Coupons\Enums\CouponStatus;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Commerz\Promotions\Definitions\Enums\PromotionStatus;
use Commerz\Promotions\Usage\Enums\CouponHoldState;
use Commerz\Promotions\Usage\Exceptions\PromotionUnavailable;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;
use RuntimeException;

/**
 * PRM-01, INV-PRM-001, D27. Locks, in the global order: the order's redemption header, then coupons, then promotions,
 * then campaigns, then customer usages, each by id ASC. Every promotion, coupon, and campaign of the order is taken
 * `for key share`, which only a change of its definition (`for update`) waits for; a counter row is updated (`no key
 * update`) only when its limit is set, so an unlimited row is never a hot row all placements queue on. Each limit is
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
        private CampaignBudgetLedger $campaigns,
        private Clock $clock,
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

            $coupons = $this->consumeCoupons($applied, $command->orderRef);
            $promotions = $this->consumePromotions($applied, $currency);
            $campaignByPromotion = $this->consumeCampaigns($applied, $promotions, $currency);
            $this->consumeCustomers($applied, $promotions, $customer);
            $this->record($redemption, $applied, $coupons, $campaignByPromotion);
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
            $query = $db->table($redemptions)->where('order_ref', $command->orderRef);
            $this->lock($query, 'for no key update');
            $redemption = $query->first(['id', 'state', 'customer_ref']);

            if ($redemption === null || Rows::string($redemption, 'state') !== 'consumed') {
                return; // never consumed, or already given back
            }

            $id = Rows::string($redemption, 'id');
            $usages = $db->table($this->tables->table('promotion_usages'))
                ->where('redemption_id', $id)
                ->get(['promotion_id', 'coupon_id', 'campaign_id', 'discount_minor'])
                ->all();
            $coupons = [];
            $campaignRestorations = [];

            foreach ($usages as $usage) {
                $coupon = Rows::nullableString($usage, 'coupon_id');

                if ($coupon !== null) {
                    $coupons[] = $coupon;
                }

                $campaignId = property_exists($usage, 'campaign_id') ? Rows::nullableString($usage, 'campaign_id') : null;

                if ($campaignId !== null) {
                    $campaignRestorations[$campaignId] ??= ['discountMinor' => 0, 'uses' => 0];
                    $campaignRestorations[$campaignId]['discountMinor'] += Rows::int($usage, 'discount_minor');
                    $campaignRestorations[$campaignId]['uses']++;
                }
            }

            $this->restoreCoupons($coupons);
            $this->restorePromotions($usages, Rows::nullableString($redemption, 'customer_ref'));
            $this->campaigns->restoreMany($campaignRestorations);

            $db->table($redemptions)->where('id', $id)->update([
                'state' => 'restored',
                'restore_reason' => $command->reason->value,
                'restored_at' => PgTimestamp::now($this->clock),
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
            [$id, $orderRef, $customer, $fingerprint, PgTimestamp::now($this->clock)],
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
     * Respects active 2-phase holds (`used + held < usage_limit`) and avoids double-counting a hold already redeemed
     * for the same `$orderRef`.
     *
     * @param  list<AppliedPromotion>  $applied
     * @return array<string, string> coupon id by promotion id
     */
    private function consumeCoupons(array $applied, string $orderRef): array
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
        $query = $db->table($table)->whereIn('code', $codes)->orderBy('id');
        $this->lock($query, 'for key share');
        $rows = $query->get(['id', 'promotion_id', 'code', 'status', 'usage_limit'])->all();

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

        $redeemedForOrder = $this->redeemedHoldsForOrder($orderRef, array_values($ids));
        $now = PgTimestamp::now($this->clock);

        foreach ($rows as $row) {
            if (Rows::nullableInt($row, 'usage_limit') === null) {
                continue;
            }

            $couponId = Rows::string($row, 'id');

            if (isset($redeemedForOrder[$couponId])) {
                continue; // already moved from held -> used when the hold was redeemed for this order
            }

            $updated = $db->update(
                "update {$this->wrap($table)} set used = used + 1, updated_at = ? where id = ? and (used + held) < usage_limit",
                [$now, $couponId],
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
        $query = $db->table($table)
            ->whereIn('id', array_map(static fn (AppliedPromotion $a): string => $a->promotionId, $applied))
            ->orderBy('id');
        $this->lock($query, 'for key share');

        $rows = [];

        foreach ($query->get(['id', 'status', 'currency', 'usage_limit', 'per_customer_limit', 'budget_minor', 'campaign_id'])->all() as $row) {
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

        $now = PgTimestamp::now($this->clock);

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
     * Step 3b: campaigns linked to the applied promotions, enforced atomically in `id ASC` order (INV-PRM-001).
     *
     * @param  list<AppliedPromotion>  $applied
     * @param  array<string, object>  $promotions
     * @return array<string, string> campaign id by promotion id
     */
    private function consumeCampaigns(array $applied, array $promotions, Currency $currency): array
    {
        $demands = [];
        $campaignByPromotion = [];
        $firstPromotionByCampaign = [];

        foreach ($applied as $promotion) {
            $row = $promotions[$promotion->promotionId];
            $campaignId = property_exists($row, 'campaign_id') ? Rows::nullableString($row, 'campaign_id') : null;

            if ($campaignId === null) {
                continue;
            }

            $campaignByPromotion[$promotion->promotionId] = $campaignId;
            $firstPromotionByCampaign[$campaignId] ??= $promotion;
            $demands[$campaignId] ??= ['discountMinor' => 0, 'uses' => 0];
            $demands[$campaignId]['discountMinor'] += $promotion->discount->amountMinor;
            $demands[$campaignId]['uses']++;
        }

        if ($demands === []) {
            return [];
        }

        try {
            $this->campaigns->consumeMany($demands, $currency);
        } catch (CampaignBudgetExceeded $e) {
            $owner = $firstPromotionByCampaign[$e->campaign] ?? $applied[0];

            if ($e->limit === 'campaign_not_active') {
                throw new PromotionUnavailable($owner->promotionId, $owner->couponCode, PromotionUnavailable::CAMPAIGN_NOT_ACTIVE);
            }

            throw new UsageLimitReached($owner->promotionId, $owner->couponCode, $e->limit);
        } catch (CampaignArchived|CampaignNotFound) {
            $owner = $applied[0];

            throw new PromotionUnavailable($owner->promotionId, $owner->couponCode, PromotionUnavailable::CAMPAIGN_NOT_ACTIVE);
        }

        return $campaignByPromotion;
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
        $now = PgTimestamp::now($this->clock);

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
     * @param  array<string, string>  $campaignByPromotion  campaign id by promotion id
     */
    private function record(string $redemption, array $applied, array $coupons, array $campaignByPromotion): void
    {
        $now = PgTimestamp::now($this->clock);

        $this->db()->table($this->tables->table('promotion_usages'))->insert(array_map(
            static fn (AppliedPromotion $a): array => [
                'redemption_id' => $redemption,
                'promotion_id' => $a->promotionId,
                'coupon_id' => $coupons[$a->promotionId] ?? null,
                'campaign_id' => $campaignByPromotion[$a->promotionId] ?? null,
                'discount_minor' => $a->discount->amountMinor,
                'currency' => $a->discount->currency->code,
                'created_at' => $now,
            ],
            $applied,
        ));
    }

    /**
     * @param  list<string>  $couponIds
     * @return array<string, true>
     */
    private function redeemedHoldsForOrder(string $orderRef, array $couponIds): array
    {
        if ($couponIds === []) {
            return [];
        }

        $redeemed = [];

        foreach ($this->db()->table($this->tables->table('coupon_holds'))
            ->where('order_ref', $orderRef)
            ->where('state', CouponHoldState::Redeemed->value)
            ->whereIn('coupon_id', $couponIds)
            ->get(['coupon_id'])
            ->all() as $row) {
            $redeemed[Rows::string($row, 'coupon_id')] = true;
        }

        return $redeemed;
    }

    /** @param  list<string>  $ids */
    private function restoreCoupons(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $db = $this->db();
        $table = $this->tables->table('coupons');
        $now = PgTimestamp::now($this->clock);
        $query = $db->table($table)->whereIn('id', $ids)->orderBy('id');
        $this->lock($query, 'for key share');

        foreach ($query->get(['id', 'usage_limit'])->all() as $row) {
            if (Rows::nullableInt($row, 'usage_limit') !== null) {
                $db->update(
                    "update {$this->wrap($table)} set used = case when used > 0 then used - 1 else 0 end, updated_at = ? where id = ?",
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
        $query = $db->table($table)->whereIn('id', array_keys($discounts))->orderBy('id');
        $this->lock($query, 'for key share');
        $rows = $query->get(['id', 'usage_limit', 'budget_minor', 'per_customer_limit'])->all();
        $now = PgTimestamp::now($this->clock);

        foreach ($rows as $row) {
            if (Rows::nullableInt($row, 'usage_limit') === null && Rows::nullableInt($row, 'budget_minor') === null) {
                continue;
            }

            $discount = $discounts[Rows::string($row, 'id')];
            $db->update(
                "update {$this->wrap($table)} set used = case when usage_limit is null then used when used > 0 then used - 1 else 0 end,"
                .' spent_minor = case when budget_minor is null then spent_minor when spent_minor > ? then spent_minor - ? else 0 end, updated_at = ?'
                .' where id = ?',
                [$discount, $discount, $now, Rows::string($row, 'id')],
            );
        }

        if ($customer === null) {
            return;
        }

        $customers = $this->tables->table('promotion_customer_usages');

        foreach ($rows as $row) {
            if (Rows::nullableInt($row, 'per_customer_limit') !== null) {
                $db->update(
                    "update {$this->wrap($customers)} set used = case when used > 0 then used - 1 else 0 end, updated_at = ? where promotion_id = ? and customer_ref = ?",
                    [$now, Rows::string($row, 'id'), $customer],
                );
            }
        }
    }

    private function lock(Builder $query, string $mode): void
    {
        $db = $this->db();

        if ($db instanceof Connection && $db->getDriverName() === 'pgsql') {
            $query->lock($mode);
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
