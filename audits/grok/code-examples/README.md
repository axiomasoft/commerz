# Примеры кода (PHP 8.4, strict)

Не пакет, а **эталон стиля и ключевых алгоритмов**. Пути показывают, где файл жил бы в пакете (CoreX STR-006:
публичные `Contracts/Data/Enums/Exceptions`, реализация — `Internal/`). Ради компактности несколько мелких типов
собраны в один файл (`Data.php`, `Enums.php`); в пакете — один тип на файл.

**Проверено, а не только написано** (`verify/`, вне пакета, `vendor/` не коммитится):

| Проверка | Результат |
|---|---|
| `composer stan` — PHPStan **level max** по всем примерам (Laravel 13, brick/math) | 0 ошибок |
| `composer smoke` — 17 поведенческих проверок: распределение, стадии акций, stacking, объяснения, купоны, `measure` | ALL PASS |
| `composer db` — миграции на PostgreSQL 17, резерв, истечение, повтор, **8 процессов × 20 резервов на 48 шт.**, **40 погашений купона с лимитом 10** | 48/48, 10/10, имена ограничений CoreX-стиля |

## Карта

| Файл | Пакет / место | Что показывает |
|---|---|---|
| `Core/Attributes.php` | core `Attributes/` | `#[Api]`, `#[Spi(kind)]` — маркировка публичной поверхности |
| `Core/Money.php`, `Currency.php`, `RoundingMode.php` | core `Money/` | int minor + brick/math; `allocate()` наибольшим остатком без переполнения |
| `Core/CommerceContext.php` | core `Context/Data` | явный неизменяемый контекст вместо singleton (Octane, DB-per-tenant) |
| `Core/CommerzConfig.php`, `ConfigSupport.php` | core `Config/` | типизированный конфиг: enum, исключение с путём ключа, без молчаливых дефолтов |
| `Core/Plugins.php` | core `Plugins/` | `CommerzPlugin` + `PluginManifest` + закрытый `PluginRegistrar` |
| `Core/Pricing.php` | core `Pricing/` | якоря конвейера, `PricingState`, `PricedLine`, `Adjustment`, `Rejection`, `PricingStage` |
| `Core/TableNames.php`, `Row.php` | core `Schema/` | логическое → физическое имя таблицы; строгое чтение строк БД |
| `Inventory/*` | commerz-inventory | `StockReservations` порт, DTO, enum, исключения, **`ReserveStockHandler`** |
| `Promotions/Contracts.php`, `Data.php`, `Enums.php` | commerz-promotions | SPI условий/эффектов/активаторов, типизированный конфиг с `VERSION` и `upgrade()` |
| `Promotions/Conditions/CartSubtotalAtLeast.php` | promotions `Internal/` | условие + конфиг v2 с миграцией с v1 |
| `Promotions/Effects/OrderPercentOff.php` | promotions `Internal/` | эффект уровня заказа, распределение по строкам |
| `Promotions/Internal/PromotionEngine.php` | promotions `Internal/` | **чистый движок стадии**: сортировка, stacking, обрезка, объяснения отказов |
| `Promotions/CartPromotionsStage.php` | promotions `Internal/Pricing` | стадия конвейера на якоре `cart_promotions` |
| `Coupons/*` | **плагин** commerz-coupons | `CouponsPlugin` manifest, `CouponActivator`, `CouponCode`, **`RedeemCouponHandler`**, условие `coupon.code_in` |
| `Catalog/AttributeType.php`, `Types/MeasureAttributeType.php` | commerz-catalog | SPI типа атрибута; `measure` с канонической единицей и проекцией |
| `Sales/OrderStates.php` | commerz-sales | три оси состояния, выводимые из сумм/количеств |
| `migrations/*` | inventory, promotions, coupons | Laravel 13 миграции: свои соединение и префикс, CHECK, частичные индексы, BRIN, `_pk`-имена как в CoreX |

## Правила, которые видны в коде

1. `final readonly` для DTO и обработчиков; `enum` с поведением (`stopsFurther()`, `derive()`, `allowedTransitions()`).
2. Ни одного `float` в деньгах, количествах и процентах (проценты — десятичные строки).
3. Обработчики записи требуют транзакцию вызывающего (`transactionLevel() === 0` → `LogicException`), сами ставят
   `SET LOCAL lock_timeout`, блокируют в детерминированном порядке.
4. Идемпотентность — естественные уникальные ключи (`demand_uq`, `coupon_id_order_id_uq`), не таблица квитанций.
5. Условия/эффекты/стадии — чистые функции над неизменяемым состоянием; запись — только владелец заказа.
6. Плагин ссылается на реализацию ключом (`'coupon'`, `'order.percent_off'`), в БД нет FQCN.
7. Названия по словарю CoreX: `*Handler`, `*Stage`, `*Activator`, `*Config`, `*Plugin`; без `Service`/`Manager`.
