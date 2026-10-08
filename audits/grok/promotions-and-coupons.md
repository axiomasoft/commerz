# Акции и купоны: движок, плагины, лимиты

Требование владельца: скидочная система — отдельный пакет внутри Commerz, купоны — отдельные плагины.
Ответ: `commerz-promotions` — движок и SPI; `commerz-coupons` — **один из плагинов-активаторов**. Движок не знает
слова «купон»: без плагина работают только автоматические акции.

## 1. Модель акции

```text
Promotion
 ├─ КТО/КОГДА: activation (automatic | ключ активатора: coupon, link, segment…), starts_at/ends_at, status
 ├─ ЕСЛИ:      conditions[] (match: all | any)   — плагины PromotionCondition
 ├─ ТО:        effects[] (с целевым фильтром строк) — плагины PromotionEffect
 ├─ КАК СОЧЕТАЕТСЯ: stage (catalog | cart | shipping), priority, stacking (stackable | exclusive | stop_further)
 └─ ЛИМИТЫ:    usage_limit, per_customer_limit, budget_minor (+валюта)
```

Хранение: `cmz_promotions` + строки `cmz_promotion_conditions` / `cmz_promotion_effects` (`type` = стабильный ключ плагина,
`config jsonb`, `config_version`). Строки, а не один JSON-документ акции (как `condition_tree` в
[logical-schema-candidate.md](../../docs/discovery/2026-10-08/logical-schema-candidate.md) §6): можно найти «все акции с
условием X» при удалении плагина, редактировать условие отдельно, проверить `config_version` построчно.

### 1.1. Встроенные условия (ключ → смысл)

| Ключ | Область | Конфиг |
|---|---|---|
| `cart.subtotal_at_least` | корзина | `Money` |
| `cart.quantity_at_least` | корзина | `int`, `LineFilter?` |
| `customer.in_group` | покупатель | `list<string>` |
| `customer.first_order` | покупатель | — (через `CustomerResolver::orderCount`) |
| `channel.in` / `store.in` | контекст | `list<ChannelId>` |
| `line.matches` | строка | `LineFilter`: товары, варианты, категории, типы, значения опций, атрибуты, sku |
| `time.weekday` / `time.hours` | контекст | для happy hours |
| `group` | составное | вложенный `match` + условия — вложенность без отдельной модели дерева |

### 1.2. Встроенные эффекты

| Ключ | Цель | Что делает |
|---|---|---|
| `line.percent_off` | строки по `LineFilter` | % от текущей цены строки, `max_quantity?`, `cap?` |
| `line.fixed_off` | строки | фикс. сумма за единицу |
| `line.fixed_price` | строки | установить цену единицы (акционная цена) |
| `order.percent_off` / `order.fixed_off` | подытог | распределяется по строкам методом наибольшего остатка |
| `buy_x_get_y` | строки | купи X из фильтра A — получи Y из фильтра B со скидкой %; самые дешёвые бесплатно; `max_applications` |
| `shipping.percent_off` / `shipping.free` | доставка | только на стадии `shipping` |
| `gift.add_line` | корзина | добавить подарок (sales создаёт строку с `is_gift`, цена 0 после эффекта) |

## 2. SPI плагина

```php
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface PromotionCondition
{
    public function key(): string;                       // 'cart.subtotal_at_least' — хранится в БД
    public function configClass(): string;               // class-string<ConditionConfig>, final readonly
    public function evaluate(ConditionConfig $config, PromotionInput $input): ConditionResult; // matched + reason
}

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface PromotionEffect
{
    public function key(): string;
    public function configClass(): string;
    /** @return list<AdjustmentProposal> */
    public function propose(EffectConfig $config, PromotionInput $input): array;  // ничего не пишет
}

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface PromotionActivator
{
    public function key(): string;                                   // 'coupon'
    /** Батч-загрузка до запуска чистого движка: коды корзины → активированные акции + отказы */
    public function activate(ActivationRequest $request): ActivationResult;
    /** В транзакции размещения: удержать лимиты активатора (лимит кода, на покупателя) */
    public function commit(ActivationCommit $commit): void;          // бросает ActivationExhausted
    public function revert(ActivationRevert $revert): void;          // отмена заказа
}
```

Полные примеры — [code-examples/Promotions](code-examples/Promotions/), [code-examples/Coupons](code-examples/Coupons/).

**Отличие от Sylius и Lunar.** Sylius `PromotionActionCommandInterface::execute(subject, array $configuration, promotion): bool`
мутирует заказ и принимает нетипизированный массив (`src/Sylius/Component/Promotion/Action/PromotionActionCommandInterface.php:19–26`);
Lunar `AbstractDiscountType` тоже меняет корзину внутри скидки (`packages/core/src/DiscountTypes/AbstractDiscountType.php:37,108`).
У нас условие и эффект — **чистые функции** над неизменяемым входом с типизированным конфигом; итог и запись
применяет только движок и владелец заказа. Это делает расчёт повторяемым, тестируемым снапшотами и безопасным для превью.

**Конфиг** хранится как `jsonb` + `config_version`; плагин отдаёт `final readonly` DTO с `fromArray()` и миграцией
`upgrade(int $fromVersion, array $raw): array`. Неизвестный `type` или невалидный конфиг при загрузке акции →
акция получает статус `unsupported` и **не применяется**, админка показывает причину (fail-closed, урок CoreX —
[ecosystem-alignment.md](ecosystem-alignment.md) §8).

## 3. Алгоритм движка

```text
input: PricingState после base/catalog-стадий, список кандидатов (активные + активированные кодами)
для stage в [catalog, cart, shipping]:
  кандидаты stage, сортировка: priority DESC, затем code ASC   (детерминизм)
  applied = []
  для p в кандидатах:
     если stopped: отказ(p, 'stopped_by', кем)
     если p.stacking = exclusive и applied ≠ ∅: отказ(p, 'exclusive_conflict')
     если не conditions(p) на ТЕКУЩЕМ состоянии: отказ(p, причины)
     proposals = effects(p) на текущем состоянии → обрезка (строка/доставка/подытог не < 0)
     state = state.withAdjustments(proposals)  ; applied += p
     если p.stacking ∈ {exclusive, stop_further}: stopped = true
вывод: корректировки с source=promotion:<id>@<revision>, объяснения применённых И отклонённых
```

| Решение | Альтернатива | Почему так |
|---|---|---|
| **Стадии** catalog → cart → shipping | один список с приоритетами (Sylius, Magento SalesRule) | акции разной природы не конкурируют по одному числу; аналог `lanes` Solidus (`promotions/app/models/solidus_promotions/promotion_lane.rb`, `promotion.rb:45`) и классов скидок Shopify (product/order/shipping) |
| Внутри стадии **последовательно** на текущей цене | параллельно от исходной цены (Shopify order discounts) | одно правило вместо матрицы «база для каждой пары»; 10 % + 5 % = 14,5 %, это документируется и видно в превью |
| `stacking` = 3 значения | флаги `combinesWith` на пару классов (Shopify), `exclusive` bool (Sylius) | покрывает «только эта», «эта и дальше ничего», «складывается»; стратегия «выбрать лучшую из эксклюзивных» — опция `ExclusiveSelection::Best` v1.1 |
| Без поиска глобального оптимума | «максимальная выгода для покупателя» перебором | экспоненциально (design-hypotheses §9 тоже отказывается); мерчант управляет приоритетом |
| Объяснение отказа — обязательная часть результата | только применённые | «почему не сработал купон» — главный вопрос поддержки; Shopify Functions возвращают диагностику кандидатам |
| Каталожные акции — та же модель, стадия `catalog`, только line/context-условия | отдельная сущность `CatalogPromotion` (Sylius), `catalogue_predicate` (Saleor `saleor/discount/models.py:431`) | одна админка и один SPI; для сортировки витрины по акционной цене — материализация в `cmz_price_catalog_index` (pricing, v1.1) |

## 4. Распределение

- Эффекты уровня строки дают сумму на строку (с учётом `max_quantity`: «скидка на 2 шт. из 5» — дешёвые/дорогие единицы по конфигу).
- Эффекты уровня заказа распределяются пропорционально текущим суммам строк методом наибольшего остатка;
  в `cmz_order_adjustments` пишется строка на каждую строку заказа с общим `application_id` — сумма = итог (PRM-02).
- `buy_x_get_y`: единицы разворачиваются, сортируются по цене (дешёвые — «подарок»), применяется до `max_applications`; детерминированный tie-break по `line_id`.
- Частичный возврат возвращает пропорциональную долю **сохранённой** аллокации строки, акция не пересчитывается.

## 5. Лимиты и бюджеты

| Лимит | Где | Как удерживается |
|---|---|---|
| Общий лимит применений акции | `cmz_promotions.usage_count/usage_limit` | `UPDATE … SET usage_count = usage_count + 1 WHERE id = :id AND (usage_limit IS NULL OR usage_count < usage_limit)` |
| Денежный бюджет акции (общий для всех кодов) | `cmz_promotions.spent_minor/budget_minor` | тот же UPDATE: `AND spent_minor + :discount <= budget_minor` (prototype E7) |
| На покупателя (без купона) | `cmz_promotion_customer_usages` | `INSERT … ON CONFLICT DO UPDATE SET usage_count = usage_count + 1 WHERE usage_count < :limit` |
| Лимит кода, на покупателя по коду | `cmz_coupons` + `cmz_coupon_redemptions` | плагин: `SELECT … FOR UPDATE` строки купона, проверка, инкремент (prototype E5–E6) |
| Журнал | `cmz_promotion_usages` (UNIQUE promotion+order) | идемпотентность, возврат при отмене |

**Когда расходуется лимит.** В транзакции размещения заказа (architecture.md §7), порядок блокировок:
`coupons (по id) → promotions (по id) → customer_usages`, `SET LOCAL lock_timeout = '3s'` (так делает Medusa:
`packages/modules/promotion/src/services/promotion-module.ts:346–372`). Если лимит кончился между расчётом корзины и
размещением — `PromotionNoLongerAvailable` с пересчитанными итогами; покупатель подтверждает новую сумму.
Возврат лимита — `UsageReleasePolicy::OnCancel` (дефолт) | `Never`; частичный возврат денег лимит не возвращает.

**Почему без claim/hold-фазы.** [logical-schema-candidate.md](../../docs/discovery/2026-10-08/logical-schema-candidate.md) §6
вводит `coupon_claims`, `promotion_quota_resources`, `promotion_quota_claims`, claim groups. Это нужно, если лимит
держат **до** заказа (в корзине) или заказ создаётся в другой транзакции, чем расход лимита. У нас расход — в той
же транзакции, что и заказ; неоплаченный заказ — это и есть «удержание» с `payment_due_at`, его отмена возвращает
лимит. Три таблицы и протокол перехода исчезают, инварианты PRM-01/PRM-02 сохраняются (проверено E5–E7).

## 6. Купоны как плагин `commerz-coupons`

| Вклад плагина | Что |
|---|---|
| Таблицы | `cmz_coupons`, `cmz_coupon_batches`, `cmz_coupon_redemptions` (FK на `cmz_promotions` — вниз по зависимости) |
| `PromotionActivator` `coupon` | `activate`: нормализует коды из `cmz_carts.codes`, батч-загружает купоны, отдаёт акции + отказы (`NotFound`, `NotStarted`, `Expired`, `Exhausted`, `CustomerLimit`, `NotForCustomer`); `commit`/`revert` — лимиты кода |
| Условие `coupon.code_in` | редкий случай «скидка если введён любой из кодов» без привязки купона |
| Команды | `GenerateCouponBatch`, `DisableCoupon`, импорт кодов |
| События | `CouponRedeemed`, `CouponExhausted` |

- **Корзина хранит «введённые коды»** (`cmz_carts.codes varchar(64)[]`) — sales не знает, купон это, реферальный код или
  код подарочной кампании; их разбирают активаторы. Так другой плагин (реферальный, `link`-активатор UTM) подключается
  без изменения sales.
- **Нормализация**: trim → upper → удалить пробелы и `-` → `^[A-Z0-9]{3,64}$` (CHECK в БД). Генерация: алфавит без
  `0 O 1 I L`, `random_bytes`, вставка пачками `ON CONFLICT DO NOTHING` до нужного количества.
- **Персональный купон** — `customer_ref` на коде. **Один купон — одна акция**; «один код на несколько акций» не
  поддерживаем (неоднозначный лимит; Medusa и Saleor тоже держат код при одной сущности).
- Защита от перебора — rate limit хоста на эндпоинте ввода кода; плагин даёт событие `CouponRejected` для метрик.

## 7. Сравнение с референсами

| Проект | Модель | Хорошо | Плохо / не берём |
|---|---|---|---|
| Sylius | Promotion + rules + actions; priority, exclusive, coupon_based, usage_limit (`Component/Promotion/Model/PromotionInterface.php:33–63`) | зрелая модель, генератор купонов, каталожные акции отдельно | `array $configuration`, мутация subject, `used` как поле без условного UPDATE |
| Solidus `solidus_promotions` | conditions / benefits / calculators, **lanes** (`promotion_lane.rb`), `usage_limit`, `per_code_usage_limit` (`promotion.rb:23–24`) | вынесен отдельным gem — ровно «скидки как отдельный пакет»; калькуляторы отдельно от benefit | лимит считается по заказам (`usage_count`), гонка |
| Saleor | Promotion + PromotionRule с JSON-предикатами; Voucher + VoucherCode(`used`) (`saleor/discount/models.py:122–264, 423–460`) | несколько кодов на ваучер, раздельные снимки `OrderLineDiscount` | JSON-предикаты — свой мини-язык без типов |
| Medusa v2 | promotion + application_method(type, target, allocation, max_quantity, buy rules) + rules(attribute, operator, values) + campaign budget (`packages/modules/promotion/src/models/*.ts`) | бюджеты кампаний (spend/usage, per attribute), FOR UPDATE с порядком и `lock_timeout` | код = поле акции (один код на акцию), правила «атрибут/оператор/строка» без типов |
| Lunar | discount types `AmountOff`, `BuyXGetY`, priority + stop | простая регистрация типов в Laravel | тип скидки сам мутирует корзину |
| Magento SalesRule | правила + `salesrule_coupon` + `times_used` (`docs/discovery/2026-10-08/external/sources/magento/magento2/app/code/Magento/SalesRule/etc/db_schema.xml`) | генерация, стоп-флаг | гигантский `serialized conditions`, interceptors везде |
| Aimeos | coupon `provider` = базовый провайдер + цепочка декораторов (`setup/default/schema/coupon.php:12–28`), `mshop_coupon_code.count` | купон как плагин-провайдер с декораторами — близко к нашей идее | строка `provider` с перечислением классов, конфиг без схемы |
| commercetools | Cart Discounts: `sortOrder`, `stackingMode` (Stacking / StopAfterThisDiscount), Discount Codes с `maxApplications`, `maxApplicationsPerCustomer` ([docs](https://docs.commercetools.com/api/projects/cartDiscounts)) | ясная семантика порядка и остановки — взята в `stacking` | предикаты на собственном языке |
| Shopify Functions | discount classes product/order/shipping, `combinesWith`, функции возвращают кандидатов ([docs](https://shopify.dev/docs/apps/build/discounts)) | плагин возвращает предложения, платформа решает | WASM-песочница — не наш масштаб |
