# Сравнение с open-source и SaaS

Исходники изучены **локально, read-only** (разреженные клоны вне репозитория, не коммитятся). Ссылки зафиксированы
на коммит, чтобы строки не «уехали». Magento/Shopware — по клонам из `docs/discovery/2026-10-08/external/sources/`.

| Проект | Коммит | Репозиторий |
|---|---|---|
| Sylius | `3931369` | https://github.com/Sylius/Sylius/tree/3931369 |
| Lunar | `82f6e4a` | https://github.com/lunarphp/lunar/tree/82f6e4a |
| Vanilo | `008296b` | https://github.com/vanilophp/framework/tree/008296b |
| Aimeos core | `9f34d23` | https://github.com/aimeos/aimeos-core/tree/9f34d23 |
| Medusa | `f274f4e` | https://github.com/medusajs/medusa/tree/f274f4e |
| Saleor | `bb75f87` | https://github.com/saleor/saleor/tree/bb75f87 |
| Solidus | `5bfab98` | https://github.com/solidusio/solidus/tree/5bfab98 |

## 1. Товары и свойства

| Проект | Модель | Что берём / почему нет |
|---|---|---|
| Magento | EAV: `catalog_product_entity_{int,decimal,varchar,text,datetime}` + scope store | **нет**: N JOIN на карточку, scope на каждое значение, индексаторы как отдельная подсистема |
| Bagisto | EAV по образцу Magento (`product_attribute_values` с колонками по типам) | **нет** по той же причине; ChatGPT §6.3 по сути предлагает этот путь |
| Akeneo PIM | значения — JSON-документ `raw_values` по атрибуту/локали/каналу | **берём** идею документа; каналы на значение — нет (v1) |
| Lunar | `attribute_data` JSON на товаре и варианте, с 2025 — `jsonb` (`packages/core/database/migrations/2025_08_14_164000_switch_to_jsonb.php:105`); группы атрибутов; опции → варианты | **берём** документ + опции; не берём хранение FQCN типа поля в значении |
| Vanilo | `property_values` + полиморфная `model_property_values` (`src/Properties/resources/database/migrations/2018_12_08_124157_create_model_property_values_table.php:12–22`) | словарные значения как строки — **берём** для `select` (`cmz_attribute_choices`); полиморфный morph без FK — нет |
| Saleor | `Attribute`/`AttributeValue` + назначение через product type (`saleor/attribute/models/base.py:135,386`; `product.py`, `product_variant.py`) | **берём** «тип товара объявляет атрибуты и уровень»; отдельные assigned-таблицы на каждый уровень — нет (документ + проекция) |
| Shopware | варианты = дочерние товары с наследованием полей от родителя; свойства — `property_group_option` (`src/Core/Content/Property`); custom fields JSON | наследование родитель→вариант даёт неочевидные значения — **нет** (у нас один уровень на атрибут) |
| Medusa | options/variants, `metadata` JSON, атрибутов как сущности нет | опции/варианты — **да**; «нет атрибутов» мало для фасетов |
| Sylius | атрибуты с типизированными колонками значения на локаль (`AttributeValueInterface`) | типы — **да**, колонки на тип + локаль в строке — нет |
| Shopify | ≤3 опций, ≤2048 вариантов; metafields с типами и definitions | лимиты-бюджеты — **да** (генератор ≤100 на раз); metafields ≈ наш документ + определения |
| commercetools | Product Type задаёт атрибуты и `attributeConstraint` (SameForAll/Unique/CombinationUnique) | «CombinationUnique» = наш `option_signature` UNIQUE |

**Вывод:** гибрид «колонки + опции реляционно + JSONB-документ + синхронная проекция» — общий знаменатель Lunar/Akeneo
(документ), Saleor/commercetools (тип товара) и Vanilo (словарь значений). Детали — [product-properties.md](product-properties.md).

## 2. Склад

| Проект | Модель | Наша оценка |
|---|---|---|
| Magento MSI | источники `inventory_source_item` + журнал `inventory_reservation` без счётчика; salable = Σ − Σ | точная история, но агрегат на каждую проверку и гонки на горячих SKU — **нет** (database.md §5.3) |
| Medusa | `inventory_level` (stocked, reserved) + `reservation_item`; проверка read-then-write (`packages/modules/inventory/src/services/inventory-module.ts:262–303`) | модель совпадает с нашей; **сериализация оставлена workflow** — мы делаем условный UPDATE (prototype E3: наивный путь теряет 620 из 640 обновлений) |
| Saleor | `Stock.quantity/quantity_allocated` + `Reservation.reserved_until` (`saleor/warehouse/models.py:527–538, 668–691`) | ленивое истечение — **берём**, но освобождаем в транзакции резерва, а не фильтром `SUM` на чтение |
| Aimeos | `mshop_stock.stocklevel` — **float** (`setup/default/schema/stock.php:39`) | float для количества — **нет** |
| Sylius | `onHand/onHold` на варианте, один склад | слишком просто для мультисклада |
| Shopify | locations + inventory levels с состояниями (available, committed, reserved, incoming) | состояния ≈ наши `on_hand/reserved/safety_stock`; «incoming» — закупки, вне v1 |

## 3. Цены и налоги

| Проект | Модель | Наша оценка |
|---|---|---|
| Medusa | `price_set` + `price` + `price_rule` (произвольные правила) + price lists (`packages/modules/pricing/src/models/`) | гибко, но правило-как-строка трудно индексировать; у нас явные колонки `channel_id, customer_group_id, min_quantity` + EXCLUDE на пересечение окон |
| Sylius | `ChannelPricing` (цена на канал) | канал как ось цены — **да** |
| Saleor | цена в `ProductVariantChannelListing` | то же; мультивалютность через канал — **да** |
| Lunar | `prices` полиморфно + customer group + tiers | tiers (`min_quantity`) — **да** |
| Magento | цена атрибутом EAV + tier/group prices + индексатор | **нет** |

## 4. Акции и купоны

| Проект | Модель | Что берём |
|---|---|---|
| Solidus (`promotions` gem) | lanes (`promotion_lane.rb`, `promotion.rb:23–24,45`), conditions/benefits, `promotion_code`, `promotion_code_batch` | **стадии** (catalog/cart/shipping) ≈ lanes; батчи кодов |
| Sylius | rules + actions, `exclusive`, `priority`, coupons при акции (`PromotionInterface.php:33–63`); action мутирует заказ, конфиг — массив (`PromotionActionCommandInterface.php:19–26`); `CatalogPromotion` отдельной сущностью | приоритет+exclusive — **да**; мутации и массивы — **нет** (чистые функции + typed config) |
| Lunar | discount types как классы, меняют корзину (`packages/core/src/DiscountTypes/AbstractDiscountType.php:37,108`) | **нет** мутаций |
| Medusa | promotion + rules + application_method + campaign budget (`packages/modules/promotion/src/models/campaign-budget.ts`); лимиты под `FOR UPDATE` + `lock_timeout` (`services/promotion-module.ts:346–372`) | порядок блокировок и `lock_timeout` — **берём**; бюджет на кампании — у нас на акции |
| Saleor | `Promotion` + `PromotionRule` с `catalogue_predicate`/`order_predicate` JSON, `Voucher` + `VoucherCode` (`saleor/discount/models.py:122–264, 423–460`) | код отдельной сущностью с лимитом — **да**; predicate-JSON — нет (строки условий) |
| Aimeos | `mshop_coupon` + `mshop_coupon_code` (count, start, end, ref) (`setup/default/schema/coupon.php:12–28`) | купон как отдельный домен поверх провайдера скидки — **да**, это и есть плагин |
| Magento SalesRule | rule + coupon, условия деревом, `stop_rules_processing`, priority | `stop_further` = `stop_rules_processing`; дерево условий — нет |
| Shopify | классы скидок product/order/shipping, `combinesWith`, Functions возвращают диагностику | классы = наши стадии; диагностика отказов — **обязательна** |
| commercetools | Product Discounts (каталог) vs Cart Discounts + Discount Codes; `stackingMode: Stacking/StopAfterThisDiscount`, `sortOrder` | почти 1:1 с нашим `stacking` и сортировкой; код активирует cart discounts — как наш активатор |

**Вывод:** купоны отдельно от акций есть у всех зрелых систем (Saleor `VoucherCode`, Solidus `promotion_code`,
Aimeos `coupon_code`, commercetools Discount Codes). Мы идём на шаг дальше: купон — **плагин-активатор**, ядро акций
знает только «введённые коды» и SPI активатора.

## 5. Заказы

| Проект | Состояние | Наша оценка |
|---|---|---|
| Sylius | три state machine: order, payment, shipping (+ checkout) | **да** — три оси (у нас выводимые payment/fulfillment) |
| Solidus/Spree | `state`, `payment_state`, `shipment_state` | то же |
| Medusa | `status` + `payment_status` + `fulfillment_status` | то же |
| Magento | один `state` + `status` | **нет**: смешение осей |

## 6. Расширяемость

| Проект | Механизм | Наша оценка |
|---|---|---|
| Magento | plugins (interceptors) на любой публичный метод, DI preferences | максимальная гибкость, минимальная предсказуемость — **нет** |
| Sylius | Symfony DI + resource override + state machine callbacks | override сущностей — **нет** (см. flexibility-and-config.md §5) |
| Lunar | `ModelManifest::replace()` | **нет** подмены моделей |
| Medusa | модули + workflows + links между модулями без FK | **да** изоляция модулей и ссылки без FK между пакетами (у нас `*_ref`) |
| Shopify | Functions: чистые функции с типизированным входом/выходом | **да** — наш SPI условий/эффектов ровно такой |
| Chatom/AzGuard (своя экосистема) | `Plugin` manifest, compiled registry, `#[Api]/#[Spi]` | **да** — копируем форму 1:1 (ecosystem-alignment.md) |

## 7. Схема и именование

| Проект | Префикс | Ключи |
|---|---|---|
| Aimeos | `mshop_` — один префикс + домен (`mshop_stock`, `mshop_coupon_code`) | int; самый близкий аналог нашего `cmz_` + существительное |
| Magento | без префикса, домен в имени (`catalog_product_entity`, `inventory_source_item`) | int |
| Sylius | `sylius_` | int |
| Lunar | `lunar_` (настраиваемый) | int |
| Vanilo | без префикса | int |
| Medusa / Saleor | без префикса | text id с префиксом типа (`prod_…`) / int+uuid |

Один короткий префикс + существительное модуля — устоявшаяся практика (Aimeos `mshop_`, Lunar `lunar_`, Sylius `sylius_`);
трёхбуквенные префиксы на каждый модуль (как в legacy-platform для продуктов) для одного пакета-семейства избыточны —
обоснование в [ecosystem-alignment.md](ecosystem-alignment.md) §5.
