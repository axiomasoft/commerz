<?php

declare(strict_types=1);

namespace Commerz\Coupons\Internal\Handlers;

use Commerz\Promotions\Data\ActivationCommit;
use Commerz\Promotions\Data\ActivationRevert;
use Commerz\Promotions\Exceptions\PromotionNoLongerAvailable;
use Commerz\Schema\Contracts\TableNames;
use Commerz\Schema\Internal\Row;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use LogicException;

/**
 * Погашение кода в транзакции размещения заказа (prototype E5–E6).
 * Порядок блокировок размещения: coupons (по id) → promotions (по id) → customer_usages.
 * Общий лимит/бюджет акции расходует пакет promotions ПОСЛЕ всех активаторов — здесь только лимиты кода.
 */
final readonly class RedeemCouponHandler
{
    public function __construct(private ConnectionInterface $db, private TableNames $tables) {}

    public function handle(ActivationCommit $commit): void
    {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('Coupon redemption must run inside the order placement transaction.');
        }
        [$coupons, $redemptions] = [$this->tables->of('coupons'), $this->tables->of('coupon_redemptions')];

        // FOR UPDATE сериализует погашения одного кода, включая подсчёт на покупателя
        $row = $this->db->selectOne(
            "SELECT id, usage_limit, usage_count, per_customer_limit, customer_ref, status
               FROM {$coupons} WHERE code = :code AND promotion_id = :promotion FOR UPDATE",
            ['code' => $commit->code, 'promotion' => $commit->promotionId],
        ) ?? throw PromotionNoLongerAvailable::for($commit->promotionId, 'coupon_not_found');
        $row = Row::of($row);
        [$couponId, $usageLimit, $usageCount, $perCustomer] = [
            $row->string('id'), $row->nullableInt('usage_limit'), $row->int('usage_count'), $row->nullableInt('per_customer_limit'),
        ];
        $owner = $row->nullableString('customer_ref');

        // идемпотентность: повтор размещения того же заказа
        $already = $this->db->selectOne(
            "SELECT 1 AS hit FROM {$redemptions} WHERE coupon_id = :coupon AND order_id = :order AND state = 'redeemed'",
            ['coupon' => $couponId, 'order' => $commit->orderId],
        );
        if ($already !== null) {
            return;
        }

        $reason = match (true) {
            $row->string('status') !== 'active' => 'coupon_disabled',
            $owner !== null && $owner !== $commit->customerRef => 'not_for_customer',
            $usageLimit !== null && $usageCount >= $usageLimit => 'exhausted',
            default => null,
        };

        if ($reason === null && $perCustomer !== null) {
            if ($commit->customerRef === null) {
                $reason = 'customer_required';
            } else {
                $used = $this->db->scalar(
                    "SELECT count(*) FROM {$redemptions} WHERE coupon_id = :coupon AND customer_ref = :customer AND state = 'redeemed'",
                    ['coupon' => $couponId, 'customer' => $commit->customerRef],
                );
                $reason = is_int($used) && $used >= $perCustomer ? 'customer_limit' : null;
            }
        }

        if ($reason !== null) {
            throw PromotionNoLongerAvailable::for($commit->promotionId, $reason);
        }

        $this->db->update("UPDATE {$coupons} SET usage_count = usage_count + 1 WHERE id = :id", ['id' => $couponId]);
        $this->db->insert(
            "INSERT INTO {$redemptions} (id, coupon_id, order_id, customer_ref, state, redeemed_at)
             VALUES (:id, :coupon, :order, :customer, 'redeemed', now())",
            ['id' => (string) Str::uuid7(), 'coupon' => $couponId, 'order' => $commit->orderId, 'customer' => $commit->customerRef],
        );
    }

    /** Отмена заказа: идемпотентно, счётчик уменьшается только если погашение было активным. */
    public function revert(ActivationRevert $revert): void
    {
        [$coupons, $redemptions] = [$this->tables->of('coupons'), $this->tables->of('coupon_redemptions')];

        $this->db->statement(<<<SQL
            WITH reverted AS (
                UPDATE {$redemptions} r SET state = 'reverted', reverted_at = now()
                  FROM {$coupons} c
                 WHERE r.coupon_id = c.id AND c.code = :code AND c.promotion_id = :promotion
                   AND r.order_id = :order AND r.state = 'redeemed'
             RETURNING r.coupon_id
            )
            UPDATE {$coupons} SET usage_count = usage_count - 1 WHERE id IN (SELECT coupon_id FROM reverted)
            SQL, ['code' => $revert->code, 'promotion' => $revert->promotionId, 'order' => $revert->orderId]);
    }
}
