# 04. Пакеты: catalog, inventory, pricing, promotions, shipping, sales, testkit, full

Для каждого пакета: назначение → сильные стороны → находки (технические `PKG-<пакет>-xx`; доменные пробелы
сведены в [05-domain-gaps.md](05-domain-gaps.md)). Размеры: `src` PHP-файлов / строк вместе с тестами.

| Пакет | src-файлов | LOC (src+tests) | тест-файлов | миграций |
|---|---|---|---|---|
| core | 180 | 11 412 | 27 | 5 |
| catalog | 150 | 12 617 | 27 | 10 |
| inventory | 124 | 11 431 | 27 | 4 |
| pricing | 81 | 7 644 | 21 | 2 |
| promotions | 106 | 12 110 | 44 | 3 |
| shipping | 90 | 8 918 | 40 | 3 |
| sales | 184 | 17 967 | 58 | 5 |
| testkit | 11 | 1 199 | 3 | — |

Крупнейшие классы (кандидаты на декомпозицию): `catalog/Variants/Internal/DatabaseVariants.php` (677),
`inventory/Reservations/Internal/DatabaseReservations.php` (629), `inventory/Transfers/Internal/DatabaseTransfers.php`
(553), `promotions/Definitions/Internal/DatabasePromotionDefinitions.php` (549), `promotions/Usage/Internal/
DatabasePromotionUsage.php` (489), `sales/Refunds/Internal/RefundJournal.php` (462).

---

## catalog

**Назначение.** Товары, варианты (оси = опции, `option_signature` из отсортированных UUID значений),
атрибуты (JSONB-документ + синхронная проекция `cmz_attribute_index`), типы товаров, категории (дерево с
advisory-lock), листинги по каналам; провайдер `variant`.

**Сильные стороны.** CAT-01 на уровне составных FK (значение ∈ опция ∈ оси товара) — инвариант нельзя обойти
даже сырым SQL; CAT-03 (фасет «red AND M» = один вариант) в одном `EXISTS`; `attribute_type` как SPI с
`configVersion`, `normalize` (идемпотентный), `index` (факты проекции), `operators`; провайдер `variant` — не
более 6 запросов на батч любого размера; неизменяемость `type/config` атрибута (D17) снимает класс гонок.

**Находки.**

- **PKG-CAT-01 · Medium · Поиск только по атрибутам, сортировка только по `handle`.** `ListProducts(channel,
  type, category, status, filters, at, first, after)` — нет полнотекстового поиска по имени, сортировок
  (цена, новизна, позиция в категории, популярность), счётчиков фасетов (D19 сознательно отложил). Для витрины
  это означает внешний поисковый движок с первого дня — но **надёжной ленты изменений нет** (KRN-09), и нет порта
  `ProductIndexer`. Рекомендация: outbox-события каталога + SPI `search_backend` (`list` делегирует, SQL — дефолт).
- **PKG-CAT-02 · Medium · `tax_class` — свободная строка.** `cmz_variants.tax_class varchar(64)` не проверяется
  против pricing (правильно — разные пакеты), но и не типизирован: опечатка → `default_class` либо
  `TaxRateNotFound` на котировке. Рекомендация: `commerz:doctor` сверяет используемые классы с pricing;
  `TaxClassRef` VO в ядре.
- **PKG-CAT-03 · Medium · Снимок теряет габариты.** В `cmz_variants` есть `length/width/height_mm`, `Parcel`
  поддерживает габариты, но `SellableSnapshot` несёт только `weightGrams` → объёмный вес для перевозчиков
  невычислим. Добавить `dimensions` в снимок (аддитивно).
- **PKG-CAT-04 · Low · `handle` глобально уникален и не локализован.** Мультиязычные ЧПУ/SEO (`/ru/futbolka`,
  `/en/t-shirt`) — на хосте. Рекомендация: `cmz_product_slugs(product_id, locale, channel_id?, slug)`.
- **PKG-CAT-05 · Low · У варианта нет собственного имени/медиа.** Имя снимка — имя товара + опции.
  Медиа отсутствуют полностью (сознательно: будущий `commerz-vaulter`), но нет даже порта `MediaRefs`.
- **PKG-CAT-06 · Low · `DatabaseVariants` 677 строк.** Генерация, создание, обновление, архивирование,
  листинг в одном классе. Выделить `VariantGeneration` (план + dry-run) и `VariantQuery`.

## inventory

**Назначение.** Локации, складские единицы (policy `tracked|backorder|untracked`), связи sellable → item ×
`quantity_per_unit` (комплекты), уровни (`on_hand`, `reserved`, `safety_stock`), журнал движений, брони по
demand с линиями, перемещения, источники канала, стратегия аллокации (SPI), reconcile.

**Сильные стороны.** Лучшая часть кодовой базы по корректности: единственный писатель уровней и движений
(`StockLedger`, проверяется `LedgerBoundaryTest`), один упорядоченный `FOR NO KEY UPDATE` на все уровни команды,
`SKIP LOCKED` только для чужих заголовков, условный `UPDATE … RETURNING` без read-then-write, учёт
переполнения разности в `numeric`, `count` как compare-and-set, физическая реальность не отвергается, а
сигнализируется `StockShortfall`, reconcile на одном `REPEATABLE READ` снимке с ремонтом только `reserved`.
Конкурентные тесты N процессов. Это уровень Vendure/Shopify после их известных багов (учтены явно).

**Находки.**

- **PKG-INV-01 · High · У локации нет адреса/гео; брони не знают пункта назначения.** `CreateLocation(code,
  name, kind)`; `ReserveStock(demand, channel, lines, expiresAt)`; `AllocationRequest(channel, needs, sources)`.
  Невозможны: «самовывоз из магазина» (BOPIS — бронь **в конкретной** точке), «ближайший склад», ship-from
  адрес для перевозчика, расчёт налога по месту отгрузки. Рекомендация: `Location::address`, опционально
  `geo`; `ReserveStock::$hint` (`AllocationHint(destination: ?Address, pickupLocation: ?string,
  deliveryMethod: ?string)`), прокидывается в `AllocationRequest`.
- **PKG-INV-02 · Medium · Стратегия аллокации глобальна** (`commerz-inventory.allocation_strategy`) — см. CFG-01.
- **PKG-INV-03 · Medium · Нет ожидаемых поступлений.** Backorder без даты, нет `incoming`/ETA, нет предзаказа
  с ограничением количества. Минимум: `StockLevel::incoming` + `available_at` в `AvailabilityAnswer`.
- **PKG-INV-04 · Low · Тяжёлые классы.** `DatabaseReservations` (629), `DatabaseTransfers` (553),
  `ReservationStore` (425), `StockLedger` (410). Логика корректна и покрыта тестами, но читать протоколы
  блокировок по 600-строчным классам тяжело; выделить «протокол» (`ReservationProtocol`) от маппинга строк.

## pricing

**Назначение.** Прайс-листы (валюта, приоритет, каналы, `TaxInclusion`), цены с окнами (`EXCLUDE` по
`tstzrange`), группы клиентов, минимальное количество, `explain`; налоги: классы, зоны (страна/регион),
ставки с окнами, `tax_calculator` SPI, стадия `tax`.

**Сильные стороны.** PRC-01/TAX-01 в БД (`EXCLUDE USING gist`, `btree_gist`); полный порядок выбора цены
(PRC-02) с одной функцией для `resolve` и `explain`; налог после всех скидок на доле, совпадающей с
settlement до копейки (D23); fail-closed при отсутствии ставки; изменение НДС по дате (РФ 22 % с
01.01.2026) — это данные, не релиз.

**Находки.**

- **PKG-PRC-01 · High · Нет цены за единицу измерения** — см. KRN-03.
- **PKG-PRC-02 · Medium · Семантика освобождения от налога.** `TaxStage` при `taxExempt` просто не добавляет
  строки налога (`TaxStage.php:57`): для цен «с НДС» освобождённый покупатель платит брутто. В ЕС B2B
  reverse-charge ожидается нетто. Рекомендация: политика `exemption: keep_gross | strip_included` (в
  каскаде CFG-01) + `CustomerProfile::taxId` и признак reverse-charge в `TaxLine` (сумма 0, код
  `reverse_charge`) — счёт-фактура требует отметки.
- **PKG-PRC-03 · Medium · Налог только по адресу доставки или глобальной зоне.** Нет происхождения
  (адрес магазина/склада — origin-based в части штатов США), нет зоны по каналу. См. CFG-01, PKG-INV-01.
- **PKG-PRC-04 · Medium · Округление налога только построчно.** Ряд юрисдикций и учётных систем требует
  округления по документу/по ставке. Режим `rounding_level: line | document` (D24 зафиксировал «по строке» —
  достаточно сделать это опцией).
- **PKG-PRC-05 · Low · Нет «контрактных» цен клиента** — только `customerGroup`. Для B2B «персональный
  прайс-лист клиента» сейчас — группа на одного клиента (работает, но засоряет группы). Рекомендация:
  `price_list.customer_refs` или назначение прайс-листа через `ContextAttributes` (KRN-07).

## promotions

**Назначение.** Акции со стадиями `catalog|cart|delivery`, условия (фильтры строк + квалификаторы), эффекты,
активаторы (`automatic`, `coupon`), stacking/exclusive/stopFurther, лимиты (всего, на клиента, купон, бюджет),
купоны (генерация по шаблону), `explain`, reconcile.

**Сильные стороны.** Детерминизм (PRM-04), «всё или ничего» на временном состоянии (PRM-05), протокол
`FOR KEY SHARE` vs `FOR UPDATE` для лимитов без горячих строк у безлимитных акций (D27), счётчики только при
наличии лимита, пересчёт из журнала, купоны — плагин к собственному SPI (D5), версионирование параметров
правил с `upgrade()`.

**Находки.**

- **PKG-PRM-01 · Medium · Загрузка всех активных акций и правил на каждую котировку.**
  `ActivePromotions::candidates()` (`ActivePromotions.php:165-178`) выбирает все активные акции канала,
  включая купонные без предъявленных кодов, затем все их правила. При тысячах персональных/купонных акций
  каждая котировка тянет всё. Рекомендация: (1) купонные акции загружать только при совпадении кода
  (активатор может объявить `requiresCode(): bool`), (2) кэш скомпилированных определений по `revision`
  (APCu/Laravel cache с инвалидацией по `PromotionChanged`), (3) индекс `(status, stage, priority, code)` +
  `promotion_channels(channel_id, promotion_id)`.
- **PKG-PRM-02 · Medium · `name` акции — не `LocalizedText`.** Имя — текст объяснения скидки покупателю
  (`Adjustment::$explanation`), но строка одна на все локали канала. Мультиязычные каналы показывают одно имя.
- **PKG-PRM-03 · Medium · Выразительность правил** — см. EXT-06.
- **PKG-PRM-04 · Low · Скрытая сессия между стадиями** — см. EXT-05.
- **PKG-PRM-05 · Low · `DatabasePromotionDefinitions` 549 строк** — создание/обновление/пауза/архив/каналы/
  explain в одном классе.

## shipping

**Назначение.** Зоны (страна/регион/префикс индекса, пересекающиеся, ранжирование по специфичности),
методы, тарифы на (метод, зона, валюта) с правилом `delivery_rate`, `free_above`, перевозчики (`carrier` SPI),
журнал бронирований с арендой и fencing.

**Сильные стороны.** Один `MethodPricer` для опций, `explain` и стадии (SHP-01); выбранный недоступный метод
валит котировку, а не молча исчезает (SHP-02); журнал бронирований — образцовый протокол unknown outcome
(D29/D30), повтор по оригинальному payload даже после смены настроек метода.

**Находки.**

- **PKG-SHP-01 · High · Нет «живых» тарифов перевозчика.** `delivery_rate` — чистая функция без сети. Для РФ
  (СДЭК, Boxberry, Почта, Яндекс Доставка) и большинства рынков цена считается API перевозчика по
  габаритам/адресу/ПВЗ. Сейчас единственный путь — синхронизировать тарифные таблицы в данные.
  Рекомендация: kind `rate_quote_provider` (сеть разрешена, **вне** транзакции и вне котировки):
  `Carts::refreshDeliveryQuotes()` получает цены и сохраняет на корзине снимок `DeliveryQuote(method,
  price, validUntil, quoteRef)`; стадия `delivery` использует снимок (чистота сохраняется), `place`
  отклоняет просроченный снимок. Так делает Shopify (carrier-calculated rates) и commercetools.
- **PKG-SHP-02 · High · Нельзя выбрать пункт выдачи.** `DeliveryOption::$meta` может нести id ПВЗ, но
  `SelectDelivery(cart, methodCode)` — только код метода. Рекомендация: `SelectDelivery(cart, methodCode,
  ?pickupPointRef, ?array $options)`; снимок в заказ; `BookShipment` получает `pickupPoint`.
- **PKG-SHP-03 · Medium · `BookShipment` беден для реальных перевозчиков.** Нет адреса отправителя (склад —
  см. PKG-INV-01), отправителя/юрлица магазина, состава отправления (для таможни и описи), объявленной
  ценности по позициям, store/channel (разные договоры с перевозчиком на магазин).
- **PKG-SHP-04 · Medium · Неизвестный вес считается 0.** `CartQuoter::shippingWeight` (`CartQuoter.php:79`):
  `weightGrams ?? 0` — тариф по весу занижается молча. Политика: `unknown_weight: zero | fail` (дефолт `fail`
  для тарифов `weight_table`).
- **PKG-SHP-05 · Low · Нет многоместных отправлений** (`Parcel` один на бронирование) и разбиения заказа на
  несколько отправлений с разными методами.

## sales

**Назначение.** Корзины/черновики, размещение, заказы-снимки, платежи (журнал попыток, inbox событий),
возвраты денег, отгрузки (в т.ч. бронирование перевозчика), возвраты товара, истечение неоплаченных заказов.

**Сильные стороны.** Одна транзакция размещения в глобальном порядке блокировок (D11), идемпотентность
`place` по корзине+версии+ожидаемой сумме (не по fingerprint котировки — продумано), `expectedTotal`
против смены цены между показом и оплатой, производные `PaymentState`/`FulfillmentState` из сумм и журнала
строк одной функцией под блокировкой заказа, inbox платёжных событий `UNIQUE(gateway, external_event_id)`,
монотонные переходы, `PaymentRequiresRefund` при оплате после отмены, fencing по `attempts`.

**Находки.**

- **PKG-SAL-01 · High · Одна строка на sellable, без свойств и метаданных** — см. EXT-04.
- **PKG-SAL-02 · High · CRM-черновик без ручной цены и скидки** — см. EXT-04. Для заявленного первичного
  потребителя (Flex CRM) это блокер: менеджерская скидка и «цена по договорённости» — повседневная операция.
- **PKG-SAL-03 · High · `GatewayPaymentRequest` не содержит store/channel, строк и налогов.** Поля:
  reference, orderRef, orderNumber, amount, customer, customerEmail, meta. Следствия: (1) мерчант-аккаунт
  по магазину не выбрать; (2) **фискализация 54-ФЗ** (ЮKassa/CloudPayments/Тинькофф требуют `receipt.items` с
  НДС и признаками предмета/способа расчёта) — адаптер вынужден ходить обратно в `Sales::orders()`;
  (3) 3DS/антифрод-данные (IP, user agent, billing address) не предусмотрены. Рекомендация: `PaymentContext`
  (store, channel, customer contact, billing address, `list<PaymentItem>` из снимка заказа, налоги) +
  `meta` для клиентских данных.
- **PKG-SAL-04 · Medium · Решение «что бронировать» принято в sales по `SellableKind`.**
  `OrderPlacement::reserveLines()` пропускает всё, что не `Physical` (`OrderPlacement.php:273`). Но у
  inventory уже есть политика `untracked` и `unlinked_sellables`. Цифровой товар с ограниченным числом
  лицензий или услуга с ёмкостью (слоты) не могут быть зарезервированы. Рекомендация: передавать все строки,
  решение — за реализацией `StockReservations` (untracked/unlinked → no-op).
- **PKG-SAL-05 · Medium · Котировка корзины падает целиком при одной исчезнувшей позиции.**
  `Sellables::resolveMany` бросает `SellableUnavailable`, `Carts::quote()` пробрасывает. Покупатель не видит
  корзину с пометкой «товар больше недоступен». Рекомендация: в котировке корзины недоступная строка — это
  `Rejection(line_unavailable)` + исключение из расчёта; `place` по-прежнему отказывает.
- **PKG-SAL-06 · Medium · Нет capture/void, многократного capture, chargeback/dispute.** D33 отложил
  capture/void; частичное списание по отгрузкам (двухстадийные платежи) и споры — обычная практика
  (Stripe, Adyen, ЮKassa «холдирование»). Добавить аддитивно: `capture(amount)`, `void`, события
  `PartiallyCaptured`, `Disputed`/`ChargebackOpened`.
- **PKG-SAL-07 · Medium · Нет редактирования размещённого заказа.** Только `cancelLines`. Замена товара,
  добавление позиции, смена адреса до отгрузки — через отмену и новый заказ (теряется номер, промо-лимиты).
  Рекомендация: «order edit» как черновик правки (`OrderEdit` → quote → `apply` с доплатой/возвратом),
  по образцу Shopify Order Editing.
- **PKG-SAL-08 · Medium · Нет оптимистической конкуренции со стороны клиента для корзины.** `version` есть,
  но команды `Carts::*` не принимают `expectedVersion` — две вкладки перезаписывают друг друга незаметно (place
  защищён). Добавить опциональный `?int $expectedVersion` во все команды корзины.
- **PKG-SAL-09 · Low · `OrderPlacement` — 16 зависимостей.** Это оркестратор, но шаги стоит оформить
  конвейером участников (EXT-04), тогда класс превращается в порядок шагов и обработку ошибок.
- **PKG-SAL-10 · Low · Нет слияния корзин (гость → вход), очистки брошенных корзин, событий
  «корзина изменена»** для маркетинга брошенных корзин.

## testkit

**Сильные стороны.** In-memory фейки всех портов ядра, `PackageTestCase`, `Concurrency` (N процессов на
PostgreSQL), `PluginContractTests`.

**Находки.**

- **PKG-TK-01 · Medium · Нет фейков feature-SPI**: `PaymentGateway` (сценарии decline/timeout/unknown/3DS),
  `Carrier` (timeout, find), `TaxCalculator`. Авторы адаптеров повторно реализуют их в каждом проекте.
- **PKG-TK-02 · Medium · Нет построителей мира.** Удобный `ShopWorld` живёт в `tests/EndToEnd/Support`
  монорепо и не поставляется. Хосту нужен `Commerce::shop()->withProduct(...)->withStock(...)->cart()`.
- **PKG-TK-03 · Low · Нет фейков часов и id** — следствие KRN-05/06.

## full

Метапакет корректен (`^0.1` на все пакеты). Замечание: `commerz:install` публикует только тег
`commerz-config` (конфиг ядра) и запускает `migrate` (`core/src/Internal/Console/InstallCommand.php:12-29`);
конфиги feature-пакетов публикуются отдельными тегами вручную, пресетов нет. Рекомендация:
`commerz:install --preset=shop|crm|pim` — публикует конфиги установленных пакетов и выставляет согласованные
значения (CRM: `stock = unlimited`, `require_delivery = false`, `payment_due_minutes = null`), затем `doctor`.
`commerz:doctor` проверяет конфиг ядра, отпечаток схемы и плагины, но не конфиги пакетов и не выбранные по
ключу вклады (CFG-03).
