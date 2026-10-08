<?php

declare(strict_types=1);

// Пакет axiomasoft/commerz, модуль Plugins: Contracts/CommerzPlugin, Data/PluginManifest, Contracts/PluginRegistrar.

namespace Commerz\Plugins\Contracts;

use Commerz\Attributes\Spi;
use Commerz\Attributes\SpiKind;
use Commerz\Plugins\Data\PluginManifest;

/** Чистые декларации: без I/O, контейнера и closures. Создаётся `new $class()` при компиляции реестра. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface CommerzPlugin
{
    public function manifest(): PluginManifest;

    public function register(PluginRegistrar $registrar): void;
}

/** Закрытый набор видов вкладов; регистрация после заморозки реестра — исключение. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface PluginRegistrar
{
    /** @param class-string<\Commerz\Catalog\Attributes\Contracts\AttributeType> $class */
    public function attributeType(string $class): void;

    /** @param class-string<\Commerz\Promotions\Contracts\PromotionCondition> $class */
    public function promotionCondition(string $class): void;

    /** @param class-string<\Commerz\Promotions\Contracts\PromotionEffect> $class */
    public function promotionEffect(string $class): void;

    /** @param class-string<\Commerz\Promotions\Contracts\PromotionActivator> $class */
    public function promotionActivator(string $class): void;

    /** @param class-string<\Commerz\Pricing\Contracts\PricingStage> $class */
    public function pricingStage(string $class): void;

    /** Таблицы плагина: логические имена обязаны начинаться с зарезервированного существительного. */
    public function migrations(string $path): void;
}

namespace Commerz\Plugins\Data;

use Commerz\Attributes\Api;
use InvalidArgumentException;

#[Api(since: '1.0')]
final readonly class PluginManifest
{
    /** @param list<string> $requires id плагинов/модулей, которые должны быть загружены раньше */
    public function __construct(public string $id, public string $version, public array $requires = [])
    {
        if (preg_match('/\A[a-z][a-z0-9]*(\.[a-z][a-z0-9_]*)+\z/D', $id) !== 1) {
            throw new InvalidArgumentException("Plugin id [{$id}] must look like 'vendor.name'.");
        }
    }
}
