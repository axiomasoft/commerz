# 03. Расширяемость и конфигурирование

Объект: пять механизмов расширения (`docs/architecture/extension.md`), реестр плагинов, contribution kinds,
порты, конфиги пакетов. Находки `EXT-xx` (расширение) и `CFG-xx` (конфигурация).

## 1. Что уже очень хорошо

- **Закрытый набор kinds, объявляемых владельцем SPI** (`declareKind`) — правильная альтернатива «хукам везде».
- **Данные хранят ключ + версию, а не класс.** Правила акций, тарифы доставки, типы атрибутов: `key` +
  `config_version` + каноничный JSONB, `upgrade(params, fromVersion)` у правила. Отсутствующий плагин →
  `unsupported`, fail-closed, видно в `reconcile`/`doctor`. Это уровень commercetools/Saleor, редкость в PHP.
- **Недоверие к результатам плагинов** в promotions (`DiscountGuard`), pricing (`TaxResultValidator`),
  shipping (`RatePrice`, валидация `BookedShipment`), sales (валидация `GatewayPaymentResult`).
- **Сетевые SPI изолированы от транзакций** (carrier, gateway) с журналом, арендой, fencing по `attempts`,
  `find()` перед повтором — образцовая реализация «unknown outcome».
- **Типизированный конфиг**: каждый пакет читает свой `config/commerz-*.php` ровно одним `*Config::fromArray` с
  путём ключа в ошибке; нет исполняемого кода в конфиге.
- `PluginContractTests` в testkit — контрактные тесты для авторов плагинов.

## 2. Находки по механизму плагинов

### EXT-01 · High · Жадное создание всех вкладов при компиляции реестра

`PluginGraphCompiler::instance()` делает `$this->container->make($declared)` для **каждого** вклада при первой
компиляции (`PluginGraphCompiler.php:231`) — нужно, чтобы узнать `key()`. Следствия:

- первая котировка/запрос в процессе конструирует все gateways, carriers, tax calculators, стадии, типы
  атрибутов — включая HTTP-клиенты адаптеров, которые этому запросу не нужны;
- вклад не может принять в конструктор то, что зависит от `Contributions` (цикл) — отсюда service locator в
  `VariantSellables.php:79`, `BaseStage`, `TaxStage`, `PromotionSessions` (см. ARC-07);
- ошибка конструктора любого вклада (не задан API-ключ перевозчика) роняет **весь** реестр — и магазин не может
  посчитать корзину из-за неработающей интеграции СДЭК. Это противоречит fail-closed-идее «вклад недоступен →
  его ключ unsupported».

Рекомендация:

```php
#[Contribution(kind: Carrier::KIND, key: 'cdek')]          // ключ известен без инстанцирования
final class CdekCarrier implements Carrier { … }

$registrar->contribute(Carrier::KIND, CdekCarrier::class);  // хранится дескриптор
$contributions->get(Carrier::KIND, 'cdek', Carrier::class); // make() при первом get(), ошибка → UnsupportedContribution
```

Компиляция валидирует классы (существует, реализует интерфейс, атрибут с ключом) без создания объектов.

### EXT-02 · Medium · Ключ вклада — утиная типизация

`$key = method_exists($contribution, 'key') ? $contribution->key() : null` (`PluginGraphCompiler.php:248`).
Каждый kind-интерфейс объявляет `key()` заново. Рекомендация: базовый `interface Contribution { public function
key(): string; }` (или атрибут из EXT-01), и `ContributionKind` требует, чтобы интерфейс kind его расширял.

### EXT-03 · Medium · Зависимости плагинов без диапазонов версий

`PluginManifest::$requires` — список id; версия плагина валидируется как semver, но **ни с чем не сравнивается**.
Плагин, написанный под SPI `promotion_effect` версии 0.1, молча загрузится с 0.2, где SPI изменился. Composer
это частично покрывает, но плагины хоста (`config('commerz.plugins')`) живут вне Composer-графа.

Рекомендация: `requires: ['commerz.promotions' => '^0.1']`, `composer/semver` для проверки; у `ContributionKind`
— `version` контракта, у вклада — `#[Contribution(spi: '1')]`; несовместимость → `InvalidConfiguration` на boot.

### EXT-04 · High · Нет точек расширения, без которых реальные хосты будут форкать sales

| Потребность | Почему нельзя сейчас | Предлагаемая точка |
|---|---|---|
| Подарочные карты, баланс клиента, списание бонусов, B2B-кредитный лимит — **атомарно с размещением** | `OrderPlacement::write()` жёстко вызывает только `StockReservations::reserve` и `PromotionUsage::consume` | kind `placement_participant` (sales): `prepare()` вне транзакции, `apply(PlacementContext)` внутри, слот в глобальном порядке блокировок, `compensate()` для отмены/истечения |
| Свойства строки (гравировка, размер на заказ, комментарий, PO-номер B2B), метаданные корзины/заказа | одна строка на sellable, в `cmz_carts/cmz_orders` нет `meta`/`attributes` | `CartLine::properties` (входит в идентичность строки), `Cart::attributes`, `Order::attributes` + kind `line_property_schema` для валидации |
| Ручная цена/скидка менеджера в CRM-черновике (D31: CRM — первичный потребитель) | `DraftLine(sellable, quantity)` — и всё; quote-стадия не видит ручных данных | `SetLinePriceOverride`/`AddManualAdjustment` в `Carts` для `CartKind::Draft` + стадия `sales.manual` + `ActorRef` в аудите |
| Валидации мутаций корзины (лимит на клиента, минимальный заказ B2B, запрет смешивания) | нет | kind `cart_policy` (pure, отказ с кодом) |
| Рабочий процесс заказа (ожидает одобрения, на удержании, фрод-проверка) | `OrderState = placed|completed|cancelled` закрыт | `holds`: список именованных удержаний на заказе (`fraud_review`, `b2b_approval`), блокирующих оплату/отгрузку; SPI `order_hold_policy` |
| Выбор платёжного способа по каналу/сумме | нет понятия «доступные способы оплаты» | порт `PaymentMethods::available(OrderView)` + настройки канала |

### EXT-05 · Medium · Скрытое состояние между «чистыми» стадиями

Promotions держит `PromotionSession` в `WeakMap<QuoteInput, PromotionSession>` (`PromotionSessions.php:19`):
стадия `promotions.catalog` загружает акции, `promotions.cart`/`promotions.delivery` читают их через побочный
канал. Это работает, но «чистота» стадий держится на соглашении и порядке; хост-стадия, пересоздающая
`QuoteInput`, или будущий параллельный прогон сломают связь незаметно.

Рекомендация: типизированные вложения в `QuoteState` (`withAttachment(object)`, `attachment(Foo::class)`),
иммутабельные, не входящие в fingerprint. Тогда сессия акций явно едет по конвейеру.

### EXT-06 · Medium · Ограниченная выразительность правил акций

- Условия объединяются только по AND (все условия промо); нет групп `any/all/not`.
- Эффекты не могут **добавить строку** (подарок при покупке без ручного добавления), нет «фиксированной цены
  комплекта», «ступенчатой скидки» (3000 → 5 %, 5000 → 10 % — приходится делать N exclusive-промо), «N-й товар
  со скидкой» кроме `buy_x_get_y`.
- Условия чистые и без IO — правильно, но тогда нужен способ передать **факты о клиенте** (первый заказ,
  сумма покупок, день рождения). Сейчас только `customerGroups`.

Рекомендация: композитное условие `group(any|all|not)` как встроенный kind; `CustomerProfile::facts`
(`array<string, scalar>`, заполняет `CustomerDirectory`) в `ConditionInput`; эффект `GiftLine` возвращает
«предложенные строки», которые sales показывает/добавляет явной командой (котировка остаётся чистой).

### EXT-07 · Low · `AllocationStrategy` не может реализовать документированный пример

`extension.md` и конфиг называют `acme.nearest`, но `AllocationRequest` содержит только `channel`, `needs`,
`sources` (`inventory/src/Allocation/Data/AllocationRequest.php`), а у локации нет адреса/координат
(`CreateLocation(code, name, kind)`). «Ближайший склад» невыразим. См. DOM-INV-01.

## 3. Находки по конфигурации

### CFG-01 · High · Политики заданы на установку, а не на магазин/канал

Цель — «много магазинов и каналов в одной БД» (D6). Но ключевые политики глобальны:

| Политика | Где | Что ломается при нескольких магазинах |
|---|---|---|
| `tax.calculator`, `default_zone`, `delivery_class`, `rounding` | `commerz-pricing` | магазин в РФ (ставки) и в ЕС (OSS, внешний калькулятор) в одной установке |
| `allocation_strategy`, `unlinked_sellables` | `commerz-inventory` | B2B-канал «одна точка отгрузки», веб — «по приоритету» |
| `number_generator`, `payment_due_minutes`, `require_delivery` | `commerz-sales` | POS без срока оплаты и без доставки + веб с 72 ч |
| `rounding`, `max_codes`, `restore_on` | `commerz-promotions` | разные правила возврата лимитов по каналам |
| `tax_included` (дефолт) | `commerz-shipping` | |
| платёжные реквизиты шлюза | адаптер шлюза | **`GatewayPaymentRequest` не содержит store/channel** → разные мерчант-аккаунты на магазин невозможны |

Рекомендация — «каскад политик»: `config` (дефолт установки) ← `store.settings` ← `channel.settings`, хранится
как данные (JSONB с кодеком владельца-пакета и `version`), читается через типизированный резолвер:

```php
$policy = $this->policies->for(SalesPolicy::class, $channelId);   // SalesPolicy: final readonly, валидирован
$policy->paymentDueMinutes; $policy->numberGenerator;
```

Пакет объявляет свою политику (`declarePolicy(SalesPolicy::class)`), ядро хранит и кэширует. Это сохраняет
принцип «конфигурация минимальна и типизирована», но даёт scope.

### CFG-02 · Medium · Ряд параметров захардкожен

- `OutboxRelay::LEASE_SECONDS = 300`, `BASE_BACKOFF_SECONDS`, `MAX_BACKOFF_SECONDS` (`OutboxRelay.php:28-32`).
- `lockTimeoutMs = 3000` по месту вызова.
- Prune outbox по `receipt_ttl_days` (общая политика хранения).
- Вес строки с неизвестным весом считается 0 (`CartQuoter.php:79`) без настройки «fail-closed».

### CFG-03 · Low · Нет `commerz:config:show`/валидации всей конфигурации разом

`commerz:doctor` есть, но конфиг каждого пакета валидируется лениво при первом резолве `*Config`. Ошибка в
`commerz-promotions.coupons.alphabet` всплывёт при первой генерации купонов. Рекомендация: `doctor` резолвит все
`*Config` и все выбранные по ключу вклады (`tax.calculator`, `allocation_strategy`, `number_generator`).

## 4. Матрица «что чем расширяется» (текущее → целевое)

| Что нужно хосту | Сейчас | Цель |
|---|---|---|
| Свой тип атрибута | `attribute_type` ✔ | ✔ |
| Своё правило акции / активатор | ✔ | + композиция условий, факты клиента |
| Свой налог (Avalara, OSS) | `tax_calculator` ✔ (глобально) | per-store политика |
| Свой перевозчик | `carrier` ✔ (только бронирование) | + `live_rate` вне транзакции с кэшем |
| Своя оплата | `payment_gateway` ✔ | + store/channel/строки в запросе, capture/void, сохранённые методы |
| Свой источник товаров (CRM) | `sellable_provider` ✔ | + quantity rules, dimensions, properties |
| Свой склад (внешний) | порт, но только локально-транзакционный | Remote-протокол (ARC-03) |
| Сборы/наценки | ✘ | `fee_rule` (KRN-02) |
| Атомарные участники размещения | ✘ | `placement_participant` (EXT-04) |
| Метаданные корзины/строки/заказа | ✘ | `attributes` + схема (EXT-04) |
| Ручная цена в CRM | ✘ | команды Draft + стадия `sales.manual` |
| Состояния/удержания заказа | ✘ | `holds` (EXT-04) |
| Политики по каналам | ✘ | каскад политик (CFG-01) |

## 5. Оценка по оси

| Критерий | Балл |
|---|---|
| Механизм плагинов (дизайн) | 8 |
| Механизм плагинов (реализация: лениво, версии) | 5 |
| Покрытие реальных точек расширения | 5 |
| Конфигурация: типизация и валидация | 9 |
| Конфигурация: scope (магазин/канал) | 3 |
