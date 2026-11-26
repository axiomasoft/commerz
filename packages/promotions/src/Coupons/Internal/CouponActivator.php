<?php

declare(strict_types=1);

namespace Commerz\Promotions\Coupons\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Promotions\Common\Data\PromotionCodes;
use Commerz\Promotions\Coupons\Enums\CouponStatus;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Commerz\Promotions\Rules\Contracts\PromotionActivator;
use Commerz\Promotions\Rules\Data\Activation;
use Commerz\Promotions\Rules\Data\ActivationRequest;
use Commerz\Promotions\Rules\Data\ActivationResult;
use Commerz\Promotions\Rules\Data\CodeRejection;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;

/**
 * Switches a promotion on when a code of one of its coupons is presented. Reads the coupons in one query and never
 * writes: the usage is spent when the order is placed. A code that is no coupon at all is not answered here; the
 * engine rejects it as `unknown_code` after every activator has had its say.
 */
#[Internal]
final readonly class CouponActivator implements PromotionActivator
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
    ) {}

    public function key(): string
    {
        return 'coupon';
    }

    public function activate(ActivationRequest $request): ActivationResult
    {
        $presented = [];

        foreach ($request->codes as $code) {
            try {
                $presented[] = [$code, PromotionCodes::couponCode('code', $code)];
            } catch (InvalidCommand) {
                continue; // not a form any coupon has
            }
        }

        if ($presented === []) {
            return new ActivationResult;
        }

        $coupons = [];

        $rows = $this->connections->connection($this->config->connection)
            ->table($this->tables->table('coupons'))
            ->whereIn('code', array_values(array_unique(array_column($presented, 1))))
            ->get(['promotion_id', 'code', 'status', 'usage_limit', 'used', 'held']);

        foreach ($rows as $row) {
            $coupons[Rows::string($row, 'code')] = $row;
        }

        $candidates = [];

        foreach ($request->promotions as $promotion) {
            $candidates[$promotion->id->value] = true;
        }

        $activations = [];
        $rejections = [];
        $seen = [];
        $activated = [];

        foreach ($presented as [$code, $canonical]) {
            $coupon = $coupons[$canonical] ?? null;

            if ($coupon === null) {
                continue;
            }

            $promotion = new PromotionId(Rows::string($coupon, 'promotion_id'));
            $limit = Rows::nullableInt($coupon, 'usage_limit');

            $held = property_exists($coupon, 'held') ? Rows::int($coupon, 'held') : 0;

            $reason = match (true) {
                isset($seen[$canonical]) => ['duplicate_code', "Code '{$canonical}' is presented more than once."],
                Rows::string($coupon, 'status') === CouponStatus::Disabled->value => ['coupon_disabled', "Coupon '{$canonical}' is disabled."],
                ! isset($candidates[$promotion->value]) => ['promotion_not_active', "The promotion of coupon '{$canonical}' does not apply to this quote."],
                $limit !== null && (Rows::int($coupon, 'used') + $held) >= $limit => ['coupon_exhausted', "Coupon '{$canonical}' has been used up."],
                isset($activated[$promotion->value]) => ['duplicate_promotion', "The promotion of coupon '{$canonical}' is already switched on by another code."],
                default => null,
            };

            $seen[$canonical] = true;

            if ($reason !== null) {
                $rejections[] = new CodeRejection($code, $reason[0], $reason[1], $promotion);

                continue;
            }

            $activated[$promotion->value] = true;
            $activations[] = new Activation($promotion, $canonical, $code);
        }

        return new ActivationResult($activations, $rejections);
    }
}
