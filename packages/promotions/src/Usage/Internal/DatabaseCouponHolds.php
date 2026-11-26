<?php

declare(strict_types=1);

namespace Commerz\Promotions\Usage\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\IdempotencyConflict;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\Uuid7;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Ports\Promotions\Exceptions\UsageLimitReached;
use Commerz\Promotions\Configuration\PromotionsOptions;
use Commerz\Promotions\Coupons\Data\CouponId;
use Commerz\Promotions\Coupons\Enums\CouponStatus;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Commerz\Promotions\Definitions\Enums\PromotionStatus;
use Commerz\Promotions\Usage\Contracts\CouponHolds;
use Commerz\Promotions\Usage\Data\CouponHoldId;
use Commerz\Promotions\Usage\Data\CouponHoldView;
use Commerz\Promotions\Usage\Data\HoldCoupon;
use Commerz\Promotions\Usage\Data\RedeemCouponHold;
use Commerz\Promotions\Usage\Data\ReleaseCouponHold;
use Commerz\Promotions\Usage\Enums\CouponHoldState;
use Commerz\Promotions\Usage\Exceptions\CouponHoldDisabled;
use Commerz\Promotions\Usage\Exceptions\CouponHoldNotFound;
use Commerz\Promotions\Usage\Exceptions\CouponHoldUnavailable;
use Commerz\Promotions\Usage\Exceptions\PromotionUnavailable;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;

/**
 * Optional 2-phase checkout coupon holds (`held -> redeemed / released`) with TTL enforced via `Clock` and atomic
 * conditional `UPDATE` over `coupons.(used + held) < usage_limit`.
 */
#[Internal]
final readonly class DatabaseCouponHolds implements CouponHolds
{
    private const string REDEEM_SCOPE = 'promotions.coupon_hold.redeem';

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private PromotionsOptions $options,
        private TableNames $tables,
        private Transactions $transactions,
        private Clock $clock,
    ) {}

    public function hold(HoldCoupon $command): CouponHoldView
    {
        if (! $this->options->holdOnCheckout) {
            throw new CouponHoldDisabled;
        }

        return $this->transactions->run(function () use ($command): CouponHoldView {
            $db = $this->db();
            $couponsTable = $this->tables->table('coupons');
            $promotionsTable = $this->tables->table('promotions');
            $holdsTable = $this->tables->table('coupon_holds');

            $couponQuery = $db->table($couponsTable)->where('code', $command->couponCode);
            $this->lock($couponQuery, 'for key share');
            $coupon = $couponQuery->first(['id', 'promotion_id', 'code', 'status', 'usage_limit']);

            if ($coupon === null) {
                throw new PromotionUnavailable('', $command->couponCode, PromotionUnavailable::COUPON_NOT_FOUND);
            }

            $couponId = Rows::string($coupon, 'id');
            $promotionId = Rows::string($coupon, 'promotion_id');
            $code = Rows::string($coupon, 'code');

            if (Rows::string($coupon, 'status') !== CouponStatus::Active->value) {
                throw new PromotionUnavailable($promotionId, $code, PromotionUnavailable::COUPON_DISABLED);
            }

            $promoQuery = $db->table($promotionsTable)->where('id', $promotionId);
            $this->lock($promoQuery, 'for key share');
            $promotion = $promoQuery->first(['id', 'status']);

            if ($promotion === null) {
                throw new PromotionUnavailable($promotionId, $code, PromotionUnavailable::PROMOTION_NOT_FOUND);
            }

            if (Rows::string($promotion, 'status') !== PromotionStatus::Active->value) {
                throw new PromotionUnavailable($promotionId, $code, PromotionUnavailable::PROMOTION_NOT_ACTIVE);
            }

            $now = PgTimestamp::current($this->clock);
            $nowText = PgTimestamp::format($now);

            $existingQuery = $db->table($holdsTable)
                ->where('coupon_id', $couponId)
                ->where('hold_ref', $command->holdRef);
            $this->lock($existingQuery, 'for no key update');
            $existing = $existingQuery->first();

            if ($existing !== null) {
                $state = CouponHoldState::from(Rows::string($existing, 'state'));
                $existingId = Rows::string($existing, 'id');

                if ($state === CouponHoldState::Held && Rows::date($existing, 'expires_at') > $now) {
                    return $this->toView($existing, $code);
                }

                if ($state === CouponHoldState::Redeemed) {
                    throw new CouponHoldUnavailable($existingId, CouponHoldUnavailable::ALREADY_REDEEMED);
                }

                throw new CouponHoldUnavailable(
                    $existingId,
                    $state === CouponHoldState::Released ? CouponHoldUnavailable::HOLD_RELEASED : CouponHoldUnavailable::HOLD_EXPIRED,
                );
            }

            $limit = Rows::nullableInt($coupon, 'usage_limit');

            if ($limit !== null) {
                $this->releaseExpiredForCoupon($couponId, $nowText);

                $updated = $db->update(
                    "update {$this->wrap($couponsTable)} set held = held + 1, updated_at = ? where id = ? and (used + held) < usage_limit",
                    [$nowText, $couponId],
                );

                if ($updated === 0) {
                    throw new UsageLimitReached($promotionId, $code, 'coupon');
                }
            }

            $ttl = $command->ttlSeconds ?? $this->options->holdTtlSeconds;
            $expiresAt = $now->add(new DateInterval("PT{$ttl}S"));
            $holdId = Uuid7::generate();

            $db->table($holdsTable)->insert([
                'id' => $holdId,
                'coupon_id' => $couponId,
                'promotion_id' => $promotionId,
                'hold_ref' => $command->holdRef,
                'customer_ref' => $command->customer === null ? null : (string) $command->customer,
                'order_ref' => null,
                'state' => CouponHoldState::Held->value,
                'held_at' => $nowText,
                'expires_at' => PgTimestamp::format($expiresAt),
                'redeemed_at' => null,
                'released_at' => null,
            ]);

            return $this->find(new CouponHoldId($holdId));
        });
    }

    public function redeem(RedeemCouponHold $command): CouponHoldView
    {
        return $this->transactions->run(function () use ($command): CouponHoldView {
            $db = $this->db();
            $holdsTable = $this->tables->table('coupon_holds');
            $couponsTable = $this->tables->table('coupons');
            $promotionsTable = $this->tables->table('promotions');

            $holdQuery = $db->table($holdsTable)->where('id', $command->hold->value);
            $this->lock($holdQuery, 'for no key update');
            $hold = $holdQuery->first();

            if ($hold === null) {
                throw new CouponHoldNotFound($command->hold->value);
            }

            $holdId = Rows::string($hold, 'id');
            $couponId = Rows::string($hold, 'coupon_id');
            $promotionId = Rows::string($hold, 'promotion_id');
            $state = CouponHoldState::from(Rows::string($hold, 'state'));

            if ($state === CouponHoldState::Redeemed) {
                if (Rows::nullableString($hold, 'order_ref') === $command->orderRef) {
                    return $this->find($command->hold);
                }

                throw new IdempotencyConflict(self::REDEEM_SCOPE, $holdId);
            }

            if ($state === CouponHoldState::Released) {
                throw new CouponHoldUnavailable($holdId, CouponHoldUnavailable::HOLD_RELEASED);
            }

            $now = PgTimestamp::current($this->clock);

            if (Rows::date($hold, 'expires_at') <= $now) {
                throw new CouponHoldUnavailable($holdId, CouponHoldUnavailable::HOLD_EXPIRED);
            }

            $couponQuery = $db->table($couponsTable)->where('id', $couponId);
            $this->lock($couponQuery, 'for key share');
            $coupon = $couponQuery->first(['id', 'code', 'status', 'usage_limit']);

            if ($coupon === null || Rows::string($coupon, 'status') !== CouponStatus::Active->value) {
                throw new CouponHoldUnavailable($holdId, CouponHoldUnavailable::COUPON_DISABLED);
            }

            $promoQuery = $db->table($promotionsTable)->where('id', $promotionId);
            $this->lock($promoQuery, 'for key share');
            $promotion = $promoQuery->first(['id', 'status']);

            if ($promotion === null || Rows::string($promotion, 'status') !== PromotionStatus::Active->value) {
                throw new CouponHoldUnavailable($holdId, CouponHoldUnavailable::PROMOTION_NOT_ACTIVE);
            }

            $nowText = PgTimestamp::format($now);

            if (Rows::nullableInt($coupon, 'usage_limit') !== null) {
                $updated = $db->update(
                    "update {$this->wrap($couponsTable)}"
                    .' set used = used + 1, held = case when held > 0 then held - 1 else 0 end, updated_at = ?'
                    .' where id = ? and used < usage_limit',
                    [$nowText, $couponId],
                );

                if ($updated === 0) {
                    throw new UsageLimitReached($promotionId, Rows::string($coupon, 'code'), 'coupon');
                }
            }

            $transitioned = $db->update(
                "update {$this->wrap($holdsTable)} set state = ?, order_ref = ?, redeemed_at = ? where id = ? and state = ?",
                [CouponHoldState::Redeemed->value, $command->orderRef, $nowText, $holdId, CouponHoldState::Held->value],
            );

            if ($transitioned === 0) {
                throw new CouponHoldUnavailable($holdId, CouponHoldUnavailable::HOLD_RELEASED);
            }

            return $this->find($command->hold);
        });
    }

    public function release(ReleaseCouponHold $command): CouponHoldView
    {
        return $this->transactions->run(function () use ($command): CouponHoldView {
            $db = $this->db();
            $holdsTable = $this->tables->table('coupon_holds');
            $couponsTable = $this->tables->table('coupons');

            $holdQuery = $db->table($holdsTable)->where('id', $command->hold->value);
            $this->lock($holdQuery, 'for no key update');
            $hold = $holdQuery->first();

            if ($hold === null) {
                throw new CouponHoldNotFound($command->hold->value);
            }

            $holdId = Rows::string($hold, 'id');
            $state = CouponHoldState::from(Rows::string($hold, 'state'));

            if ($state === CouponHoldState::Released) {
                return $this->find($command->hold);
            }

            if ($state === CouponHoldState::Redeemed) {
                throw new CouponHoldUnavailable($holdId, CouponHoldUnavailable::ALREADY_REDEEMED);
            }

            $couponId = Rows::string($hold, 'coupon_id');
            $couponQuery = $db->table($couponsTable)->where('id', $couponId);
            $this->lock($couponQuery, 'for key share');
            $coupon = $couponQuery->first(['id', 'usage_limit']);

            $nowText = PgTimestamp::now($this->clock);
            $updated = $db->update(
                "update {$this->wrap($holdsTable)} set state = ?, released_at = ? where id = ? and state = ?",
                [CouponHoldState::Released->value, $nowText, $holdId, CouponHoldState::Held->value],
            );

            if ($updated > 0 && $coupon !== null && Rows::nullableInt($coupon, 'usage_limit') !== null) {
                $db->update(
                    "update {$this->wrap($couponsTable)} set held = case when held > 0 then held - 1 else 0 end, updated_at = ? where id = ?",
                    [$nowText, $couponId],
                );
            }

            return $this->find($command->hold);
        });
    }

    public function find(CouponHoldId $id): CouponHoldView
    {
        $db = $this->db();
        $holdsTable = $this->tables->table('coupon_holds');
        $couponsTable = $this->tables->table('coupons');

        $row = $db->table($holdsTable.' as h')
            ->join($couponsTable.' as c', 'c.id', '=', 'h.coupon_id')
            ->where('h.id', $id->value)
            ->first([
                'h.id', 'h.coupon_id', 'h.promotion_id', 'c.code as coupon_code', 'h.hold_ref',
                'h.customer_ref', 'h.order_ref', 'h.state', 'h.held_at', 'h.expires_at', 'h.redeemed_at', 'h.released_at',
            ]);

        if ($row === null) {
            throw new CouponHoldNotFound($id->value);
        }

        return $this->toView($row, Rows::string($row, 'coupon_code'));
    }

    public function expireDue(?DateTimeImmutable $asOf = null): int
    {
        $cutoff = PgTimestamp::format($asOf ?? PgTimestamp::current($this->clock));

        return $this->transactions->run(function () use ($cutoff): int {
            $db = $this->db();
            $holdsTable = $this->tables->table('coupon_holds');
            $couponsTable = $this->tables->table('coupons');

            $expiredQuery = $db->table($holdsTable)
                ->where('state', CouponHoldState::Held->value)
                ->where('expires_at', '<=', $cutoff)
                ->orderBy('coupon_id')
                ->orderBy('id');
            $this->lock($expiredQuery, 'for update skip locked');

            $expired = $expiredQuery->get(['id', 'coupon_id'])->all();

            if ($expired === []) {
                return 0;
            }

            $ids = [];
            $byCoupon = [];

            foreach ($expired as $row) {
                $id = Rows::string($row, 'id');
                $couponId = Rows::string($row, 'coupon_id');
                $ids[] = $id;
                $byCoupon[$couponId] = ($byCoupon[$couponId] ?? 0) + 1;
            }

            $db->table($holdsTable)
                ->whereIn('id', $ids)
                ->where('state', CouponHoldState::Held->value)
                ->update([
                    'state' => CouponHoldState::Released->value,
                    'released_at' => $cutoff,
                ]);

            ksort($byCoupon, SORT_STRING);
            $couponQuery = $db->table($couponsTable)
                ->whereIn('id', array_keys($byCoupon))
                ->orderBy('id');
            $this->lock($couponQuery, 'for key share');

            foreach ($couponQuery->get(['id', 'usage_limit'])->all() as $coupon) {
                if (Rows::nullableInt($coupon, 'usage_limit') === null) {
                    continue;
                }

                $couponId = Rows::string($coupon, 'id');
                $count = $byCoupon[$couponId];
                $db->update(
                    "update {$this->wrap($couponsTable)} set held = case when held > ? then held - ? else 0 end, updated_at = ? where id = ?",
                    [$count, $count, $cutoff, $couponId],
                );
            }

            return count($ids);
        });
    }

    private function releaseExpiredForCoupon(string $couponId, string $nowText): void
    {
        $db = $this->db();
        $holdsTable = $this->tables->table('coupon_holds');
        $couponsTable = $this->tables->table('coupons');

        $expiredQuery = $db->table($holdsTable)
            ->where('coupon_id', $couponId)
            ->where('state', CouponHoldState::Held->value)
            ->where('expires_at', '<=', $nowText)
            ->orderBy('id');
        $this->lock($expiredQuery, 'for update skip locked');

        $ids = array_map(
            static fn (object $row): string => Rows::string($row, 'id'),
            $expiredQuery->get(['id'])->all(),
        );

        if ($ids === []) {
            return;
        }

        $released = $db->table($holdsTable)
            ->whereIn('id', $ids)
            ->where('state', CouponHoldState::Held->value)
            ->update([
                'state' => CouponHoldState::Released->value,
                'released_at' => $nowText,
            ]);

        if ($released > 0) {
            $db->update(
                "update {$this->wrap($couponsTable)} set held = case when held > ? then held - ? else 0 end, updated_at = ? where id = ?",
                [$released, $released, $nowText, $couponId],
            );
        }
    }

    private function toView(object $row, string $couponCode): CouponHoldView
    {
        $customer = Rows::nullableString($row, 'customer_ref');
        $redeemedAt = Rows::nullableString($row, 'redeemed_at');
        $releasedAt = Rows::nullableString($row, 'released_at');

        return new CouponHoldView(
            id: new CouponHoldId(Rows::string($row, 'id')),
            couponId: new CouponId(Rows::string($row, 'coupon_id')),
            promotionId: new PromotionId(Rows::string($row, 'promotion_id')),
            couponCode: $couponCode,
            holdRef: Rows::string($row, 'hold_ref'),
            customer: $customer === null ? null : CustomerRef::fromString($customer),
            orderRef: Rows::nullableString($row, 'order_ref'),
            state: CouponHoldState::from(Rows::string($row, 'state')),
            heldAt: Rows::date($row, 'held_at'),
            expiresAt: Rows::date($row, 'expires_at'),
            redeemedAt: $redeemedAt === null ? null : new DateTimeImmutable($redeemedAt),
            releasedAt: $releasedAt === null ? null : new DateTimeImmutable($releasedAt),
        );
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
