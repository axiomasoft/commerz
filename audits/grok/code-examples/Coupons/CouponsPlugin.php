<?php

declare(strict_types=1);

namespace Commerz\Coupons;

use Commerz\Coupons\Internal\CouponActivator;
use Commerz\Coupons\Internal\Conditions\CodeIn;
use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\Contracts\PluginRegistrar;
use Commerz\Plugins\Data\PluginManifest;
use Override;

/**
 * Купоны — отдельный пакет axiomasoft/commerz-coupons. Подключение: composer require + строка в commerz.plugins.
 * Ядро акций о купонах не знает: корзина хранит общие «введённые коды», их разбирает активатор 'coupon'.
 */
final class CouponsPlugin implements CommerzPlugin
{
    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(id: 'commerz.coupons', version: '1.0.0', requires: ['commerz.promotions']);
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->promotionActivator(CouponActivator::class);
        $registrar->promotionCondition(CodeIn::class);
        $registrar->migrations(__DIR__.'/../database/migrations');   // cmz_coupons, cmz_coupon_batches, cmz_coupon_redemptions
    }
}
