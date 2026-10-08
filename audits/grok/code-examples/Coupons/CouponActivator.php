<?php

declare(strict_types=1);

namespace Commerz\Coupons\Internal;

use Commerz\Coupons\Data\CouponCode;
use Commerz\Coupons\Internal\Handlers\RedeemCouponHandler;
use Commerz\Promotions\Contracts\PromotionActivator;
use Commerz\Promotions\Data\ActivationCommit;
use Commerz\Promotions\Data\ActivationRejection;
use Commerz\Promotions\Data\ActivationRequest;
use Commerz\Promotions\Data\ActivationResult;
use Commerz\Promotions\Data\ActivationRevert;
use Commerz\Promotions\Enums\RejectionReason;
use Override;

final readonly class CouponActivator implements PromotionActivator
{
    public function __construct(private CouponReader $coupons, private RedeemCouponHandler $redeem) {}

    #[Override]
    public function key(): string
    {
        return 'coupon';
    }

    /** Один батч-запрос на все коды корзины; ни одной записи — расчёт корзины можно повторять сколько угодно. */
    #[Override]
    public function activate(ActivationRequest $request): ActivationResult
    {
        $codes = array_values(array_filter(array_map(CouponCode::tryNormalize(...), $request->codes)));
        $found = $this->coupons->findByCodes($codes, $request->context);   // array<string, CouponView>

        $candidates = [];
        $rejections = [];
        foreach ($codes as $code) {
            $coupon = $found[$code->value] ?? null;
            $reason = $coupon === null ? RejectionReason::CodeNotFound : $coupon->rejectionFor($request->context);

            if ($reason !== null) {
                $rejections[] = new ActivationRejection($code->value, $reason);
                continue;
            }
            // активированная акция приходит из репозитория промо уже гидратированной (status=active, плагины известны)
            $candidates[] = $coupon->promotion->activatedBy($code->value);
        }

        return new ActivationResult($candidates, $rejections);
    }

    #[Override]
    public function commit(ActivationCommit $commit): void
    {
        $this->redeem->handle($commit);
    }

    #[Override]
    public function revert(ActivationRevert $revert): void
    {
        $this->redeem->revert($revert);
    }
}
