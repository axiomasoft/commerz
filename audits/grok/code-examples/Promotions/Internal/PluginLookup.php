<?php

declare(strict_types=1);

namespace Commerz\Promotions\Internal;

use Commerz\Promotions\Contracts\PromotionCondition;
use Commerz\Promotions\Contracts\PromotionEffect;

/** Узкий взгляд на замороженный PluginRegistry: ключ → реализация (контейнер разрешает лениво). */
interface PluginLookup
{
    public function condition(string $key): PromotionCondition;

    public function effect(string $key): PromotionEffect;
}
