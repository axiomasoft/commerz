# Проверенные выводы после Perplexity: commerce, PHP/Laravel

Дата проверки: 2026-10-08. Это исследование для проектирования Commerz, не спецификация готового пакета. Ответы Perplexity находятся рядом и считаются материалом для проверки. В этом документе `факт` означает прочитанную первичную документацию или конкретный исходник; `предложение` — наш вывод для обсуждения; `не установлено` — граница проверки.

## Версии и воспроизводимость

| Проект | Прочитанная ветка | Commit | Область чтения |
|---|---|---|---|
| Sylius/Sylius | 2.3 | `39313695548c709756ee9073bf309fa4ae89365a` | AttributeValue, inventory checker/operator/decorator, promotion processor, workflow listeners |
| lunarphp/lunar | 1.x | `82f6e4a64585ac5f11ca1fce4b6897be870e691a` | Typed JSON cast, field/model registries, cart pipeline, discounts, order creation and lines, Purchasable |
| bagisto/bagisto | 2.5 | `e32b99a97c51c803fa2a1561e3542c8043919256` | Attribute migrations/model, coupon usage listener, order transaction and event binding |
| PostgreSQL | documentation 18 | versioned manual | Isolation, row locks, JSONB, constraints |

Локальные копии выбранных исходников, размеры и commit-pinned URLs: [sources/manifest.json](sources/manifest.json). Лицензии проектов сохранены рядом с исходниками. Полные git trees служат указателем для дальнейшего чтения, не означают, что прочитан каждый файл. Код соседних приватных проектов в Perplexity не передавался.

## 1. Типизированные атрибуты: EAV и JSON решают разные части задачи

### F01 — Lunar: типы в PHP, данные в JSON

**Факт.** `AsAttributeData::set()` сериализует каждое значение как `field_type` с именем PHP-класса и `value`. При чтении класс проверяется на существование и реализацию `FieldType`, затем создаётся с сохранённым значением. Несуществующий класс пропускается через `continue`. Это конкретная связь формата хранимых данных с namespace класса. [Исходник cast](https://github.com/lunarphp/lunar/blob/82f6e4a64585ac5f11ca1fce4b6897be870e691a/packages/core/src/Base/Casts/AsAttributeData.php).

**Факт.** Официальная документация описывает JSON `attribute_data`, поддержку атрибутов у Product и ProductVariant, кастомные типы через FieldTypeManifest, отдельные searchable/filterable flags и Laravel validation rules. Эти метаданные не доказывают, что каждый путь записи автоматически выполняет валидацию или что каждый поисковый адаптер индексирует произвольный тип. [Lunar attributes 1.x](https://docs.lunarphp.com/1.x/reference/attributes).

**Предложение.** Сохранить удобство типизированных значений, но долговечный type id сделать независимым от FQCN: например, `commerz.measurement` плюс `codec_version`. Registry сопоставляет id с реализацией. Неизвестный type id должен быть видимым состоянием ошибки/неподдерживаемого значения, а не незаметной потерей атрибута при чтении. Тип должен определять decode/encode/validate/normalize/index projection отдельно от UI renderer.

### F02 — Bagisto: одна EAV-таблица с несколькими типизированными колонками

**Факт.** Прочитанная миграция `product_attribute_values` создаёт `text_value`, `boolean_value`, `integer_value`, `float_value` как `decimal(12,4)`, `datetime_value`, `date_value`, `json_value`, ссылки product/attribute, nullable channel/locale. Это не схема «отдельная таблица на каждый тип». В миграции есть составной unique по scope и owner, поздняя миграция добавляет отдельный `unique_id`. [Исходная миграция](https://github.com/bagisto/bagisto/blob/e32b99a97c51c803fa2a1561e3542c8043919256/packages/Webkul/Product/src/Database/Migrations/2018_07_27_070011_create_product_attribute_values_table.php), [добавление unique_id](https://github.com/bagisto/bagisto/blob/e32b99a97c51c803fa2a1561e3542c8043919256/packages/Webkul/Product/src/Database/Migrations/2024_01_19_170350_add_unique_id_column_in_product_attribute_values_table.php).

**Не установлено.** По двум миграциям нельзя утверждать, что все пути записи корректно заполняют scope/unique_id, либо что ни одна последующая миграция не изменяет этот контракт. Мы не запускали миграции Bagisto и не проверяли конкурентный импорт.

**Предложение.** Разделять логический тип, физическое хранение и визуальный editor. Поле с именем `float_value` может физически быть DECIMAL; названия сами по себе не доказывают арифметические свойства. Scope следует определить до выбора unique constraint. Для `global/channel/locale/channel+locale` нужна явная политика fallback и различие «значение отсутствует», `null`, «запретить наследование».

### F03 — Sylius: value направляется в поле по storage type

**Факт.** У `AttributeValue` есть поля text/boolean/integer/float/datetime/date/json и localeCode. `getValue()`/`setValue()` выбирают соответствующий accessor по `attribute->getStorageType()`. Это подтверждает приложение с типизированными значениями, но сам PHP-класс ещё не доказывает точный DDL и все SQL constraints. [AttributeValue](https://github.com/Sylius/Sylius/blob/39313695548c709756ee9073bf309fa4ae89365a/src/Sylius/Component/Attribute/Model/AttributeValue.php).

**Предложение.** Возможны минимум три решения для Commerz:

| Решение | Где полезно | Цена решения | Что проверить прототипом |
|---|---|---|---|
| Relational core + typed JSON document | Каталог умеренного размера, быстрый full-product read, разработчик контролирует схемы | Более крупная единица обновления; сложнее FK и уникальность внутри документа; indexing adapter обязателен для серьёзных facets | Одновременное изменение разных атрибутов; numeric range + locale fallback; перенос type id |
| Relational core + typed EAV rows | Много runtime-defined свойств и контекстных переопределений; независимые обновления значений | JOIN/pivot стоимость, сборка продукта, усложнение ограничений по типу | Cardinality/NULL scope uniqueness; план facet query; batch import |
| Relational core + referenced structured records + JSON metadata | Материалы, размерные таблицы, сертификаты, совместимые устройства переиспользуются как самостоятельные объекты | Дополнительные сущности, reference lifecycle и миграции | Удаление referenced object; publish/dependency validation; historical snapshots |

Это альтернативы для benchmark, а не готовый выбор. Не следует делать деньги, цены, остатки и транзакционные идентификаторы произвольными атрибутами только ради одинакового интерфейса.

### F04 — Что действительно даёт PostgreSQL JSONB

**Факт.** JSONB поддерживает индексы, в том числе GIN; operator classes отличаются поддерживаемыми операциями. Из этого не следует, что один GIN решает произвольные диапазоны чисел, сортировку и подсчёт facets. JSONB-обновление также не делает две конкурентные записи одного документа независимыми. [PostgreSQL 18 JSON types](https://www.postgresql.org/docs/18/datatype-json.html).

**Предложение.** Обсуждать EAV против JSON на измеримых запросах: 6–10 фильтров, numeric range, locale fallback, multi-select OR внутри facet/AND между facets, сортировка, обновление одной характеристики, import throughput. Search projection должна иметь rebuild/reconcile protocol, revision и измеримый lag. Поисковая выдача допустимо отстаёт; checkout не должен принимать обязательства по этой выдаче.

### F05 — Уникальность nullable scopes требует сознательного решения

**Факт.** В PostgreSQL обычный unique constraint по умолчанию не считает NULL равным NULL; PostgreSQL поддерживает `NULLS NOT DISTINCT`. FK и unique работают на определённых ключах, а не на бизнес-смысле JSON. [PostgreSQL 18 constraints](https://www.postgresql.org/docs/18/ddl-constraints.html).

**Предложение.** Не переносить составной unique из чужой миграции без проверки DB engine. Кандидаты: нормализованный non-null scope identity; частичные индексы для конкретных scopes; PostgreSQL `NULLS NOT DISTINCT`, если PostgreSQL принят как требование. Если нужны MySQL и PostgreSQL одновременно, ограничения должны быть спроектированы и проверены отдельно. Возможность Laravel Schema Builder сгенерировать DDL не равна одинаковой семантике.

## 2. Inventory: смысл counters, место конкуренции и жизненный цикл

### F06 — Sylius: checker, operator и locking decorator — разные части

**Факт.** AvailabilityChecker сравнивает requested quantity с `onHand - onHold`, если tracking включён. Доменный hold увеличивает onHold; sell уменьшает onHold и onHand с проверками отрицательного результата; cancel выбирает возврат onHand либо снятие hold в зависимости от payment state. [Checker](https://github.com/Sylius/Sylius/blob/39313695548c709756ee9073bf309fa4ae89365a/src/Sylius/Component/Inventory/Checker/AvailabilityChecker.php), [domain operator](https://github.com/Sylius/Sylius/blob/39313695548c709756ee9073bf309fa4ae89365a/src/Sylius/Component/Core/Inventory/Operator/OrderInventoryOperator.php).

**Факт.** Doctrine decorator перед cancel/hold/sell использует `LockMode::OPTIMISTIC` и version варианта. Следовательно, фраза «Sylius просто делает read-modify-write без concurrency механизма» была бы неверна по прочитанному коду. [Decorator](https://github.com/Sylius/Sylius/blob/39313695548c709756ee9073bf309fa4ae89365a/src/Sylius/Bundle/CoreBundle/Doctrine/ORM/Inventory/Operator/OrderInventoryOperator.php).

**Не установлено.** Здесь не проверена вся регистрация listeners, ordering checkout transitions, retry при optimistic conflict и every write path. Имена counters не доказывают физический warehouse ledger. Для Commerz отдельно определить, уменьшается ли physical on hand при оплате или при фактической отгрузке.

### F07 — Ledger сам по себе не предотвращает oversell

**Факт.** PostgreSQL READ COMMITTED даёт обычному SELECT снимок на начало запроса. Конкурентный UPDATE ждёт другого writer и перепроверяет WHERE по обновлённой версии строки. REPEATABLE READ допускает serialization anomalies; SERIALIZABLE может отклонить транзакцию и требует повторить её полностью. [Isolation, PostgreSQL 18](https://www.postgresql.org/docs/18/transaction-iso.html).

**Факт.** Row locks блокируют конфликтующие writes/locks до завершения транзакции; deadlocks возможны, единый порядок блокировок снижает риск. [Explicit locking, PostgreSQL 18](https://www.postgresql.org/docs/18/explicit-locking.html).

**Предложение.** Для первой проверки рассмотреть один синхронный balance row на allocation key + reservation records + append-only journal, записываемые одной транзакцией. Прочитанный ledger sum без блокировки общего ресурса позволяет двум checkout принять одну и ту же последнюю единицу. Не выбирать asynchronous projection как authoritative admission balance.

### Эксперимент I1 — один товар, последний остаток, два checkout

Это предлагаемый протокол для одной PostgreSQL БД, не утверждение о внутренностях Shopify или Magento:

1. Проверить operation idempotency key и hash нормализованного payload; конфликтующий payload с тем же ключом отклонить.
2. Иметь заранее созданную balance row с уникальным allocation key. Блокировка несуществующей строки не создаёт защиту сама по себе.
3. В одной транзакции либо взять строку `FOR UPDATE`, проверить доступность и обновить, либо выполнить guarded UPDATE с условием доступности и проверить affected rows.
4. Создать reservation/allocation и journal entry, сохранить результат команды и outbox record в той же транзакции.
5. При zero updated rows вернуть отсутствие доступного остатка; при rollback не должно остаться accepted order без соответствующих обязательств.
6. Provider HTTP call выполняется после локального commit. Повтор callback идемпотентен по business operation identity, даже если пришёл с другим request id.

Для корзины из нескольких inventory items отсортировать все allocation keys перед блокировкой и агрегировать повторяющиеся компоненты bundles. Повтор целой транзакции ограничен и отделён от повторов сетевого запроса провайдеру. Этот протокол надо испытывать настоящими конкурентными соединениями, а не одиночным unit test.

### Эксперимент I2 — pool overlap

Warehouse W содержит 10 штук. Channel A и Channel B оба используют W. Две независимые channel balances по 10 создают ложную ёмкость 20. Проверить три альтернативы:

- Оба канала сериализуют claims на общей capacity W.
- Каналам выделены непересекающиеся entitlements 6+4.
- Pooled reservation имеет canonical shared reservation domain и корректное позднее allocation.

Выбор влияет на location assignment при checkout, split shipments, самовывоз, перенос заказа и backorder. Ни один вариант не следует автоматически из наличия таблицы `stocks`.

### Эксперимент I3 — TTL против успешной оплаты

Hold истекает в 12:00:00. Payment callback приходит в 12:00:01 после освобождения ёмкости. Нужна согласованная политика: повторное приобретение supply; ожидание/exception; void/refund. Запрещён переход expired → committed без проверки доступности. Expiration worker и payment acceptance должны менять один и тот же lifecycle под согласованной блокировкой/version check. Наличие `expires_at` само по себе не освобождает остаток.

### Эксперимент I4 — refund не равен restock

Сценарии отдельно: отмена неотгруженного остатка снимает commitment; финансовый refund не создаёт физические товары; принятие возврата создаёт receipt в quarantine; inspection переводит quantity в sellable/damaged. Transfer dispatch создаёт in-transit supply; transfer receipt создаёт supply у получателя. Нельзя считать incoming одновременно доступным и на source, и на destination.

## 3. Купоны, деньги и расширения

### F08 — Bagisto перепроверяет coupon limit внутри order transaction

**Факт.** Listener `manageCartRule` блокирует rule/coupon rows через `lockForUpdate`, читает usage counters, повторно проверяет global/per-customer ограничения и затем увеличивает counters. Event binding направляет `checkout.order.save.after` в этот listener; OrderRepository вызывает событие до `DB::commit()`, ловит coupon-limit exception и откатывает order transaction. [Listener](https://github.com/bagisto/bagisto/blob/e32b99a97c51c803fa2a1561e3542c8043919256/packages/Webkul/CartRule/src/Listeners/Order.php), [event binding](https://github.com/bagisto/bagisto/blob/e32b99a97c51c803fa2a1561e3542c8043919256/packages/Webkul/CartRule/src/Providers/EventServiceProvider.php), [OrderRepository](https://github.com/bagisto/bagisto/blob/e32b99a97c51c803fa2a1561e3542c8043919256/packages/Webkul/Sales/src/Repositories/OrderRepository.php).

**Не установлено.** Это не полный concurrency audit Bagisto: не проверены все cancellation/return paths, plugins, guest identity, shared promotion budgets и test execution. Нельзя автоматически перенести гарантию локальной транзакции в system, где coupon service использует другую БД.

**Предложение.** Сделать budget admission явной частью checkout command. Preview не расходует coupon. Reservation, redemption и reversal — различные операции. Shared promotion limit сериализуется отдельно от отдельных coupon codes. Повтор с тем же business checkout identity не съедает дополнительное использование.

### F09 — Lunar защищает повтор draft order от повторного расхода discount

**Факт.** `CreateOrder` выполняется в `DB::transaction`, до creation pipeline читает `consumedDiscountIds()`, после pipeline вызывает `markAsUsed` лишь для ещё не потреблённых скидок, затем сбрасывает memoized IDs. Это конкретная защита сценария повторного checkout после отказа карты. Она сама по себе не доказывает защиту общего лимита от двух разных одновременных покупателей. [CreateOrder](https://github.com/lunarphp/lunar/blob/82f6e4a64585ac5f11ca1fce4b6897be870e691a/packages/core/src/Actions/Carts/CreateOrder.php).

**Предложение.** Отдельно тестировать «тот же checkout повторён» и «два разных checkout соревнуются за последний coupon». Idempotency и capacity limit закрывают разные ошибки.

### F10 — Sylius пересчитывает promotions с откатом прежних effects

**Факт.** PromotionProcessor сначала вызывает revert для прежних promotions. Затем отдельно проверяет exclusive promotions и возвращается после применения первой eligible exclusive; если такой нет, применяет eligible non-exclusive. Sorting поступающего набора не определяется этим файлом. Поэтому по одному processor нельзя утверждать конкретное направление priority. [PromotionProcessor](https://github.com/Sylius/Sylius/blob/39313695548c709756ee9073bf309fa4ae89365a/src/Sylius/Component/Promotion/Processor/PromotionProcessor.php).

**Предложение.** Для Commerz рассмотреть чистый расчёт adjustments из immutable input вместо накопления мутаций на модели. Повторный расчёт должен давать те же allocations. Eligibility basis фиксируется до action: правило «скидка при сумме ≥ 100» не должно само отменяться после снижения суммы.

### F11 — Lunar: упорядоченные pipelines и собственные discount types

**Факт.** Конфигурация cart содержит упорядоченный список стадий CalculateLines → ApplyShipping → ApplyDiscounts → CalculateTax → Calculate. DiscountManager предоставляет регистрацию типов, сортирует discounts по descending priority, затем id, и прекращает loop по stop лишь если discount действительно применился. [Cart config](https://github.com/lunarphp/lunar/blob/82f6e4a64585ac5f11ca1fce4b6897be870e691a/packages/core/config/cart.php), [DiscountManager](https://github.com/lunarphp/lunar/blob/82f6e4a64585ac5f11ca1fce4b6897be870e691a/packages/core/src/Managers/DiscountManager.php).

**Предложение.** У каждой стадии Commerz должны быть declared input/output и правило порядка. Нужны диагностируемые duplicate IDs, неизвестные dependencies и cycles. «Максимальная гибкость» не должна означать, что любой plugin произвольно меняет tax base, accepted totals или inventory counters. Изменяемость формы данных, подмена алгоритма и интеграционные events — три разных механизма.

### F12 — Model replacement конфликтует как способ композиции plugins

**Факт.** Bagisto документирует контракт, proxy, Eloquent implementation и регистрацию через Concord. Для одного контракта используется одна реализация; последняя зарегистрированная побеждает. Два packages, заменяющих одну модель, конфликтуют. Для добавления только relation docs предлагают `resolveRelationUsing`. [Bagisto model extension](https://devdocs.bagisto.com/package-development/models).

**Предложение.** Основной путь — стратегии и registry: AttributeType, PriceResolver, PromotionRule, PromotionAction, AllocationPolicy, InventoryDemandResolver, ShippingProvider, PaymentGateway. Host application может явно выбрать model override как отдельное решение. Plugin не должен незаметно выигрывать за счёт service-provider load order.

### F13 — Историческая order line уже содержит snapshot-поля в Lunar

**Факт.** CreateOrderLines копирует в строку заказа description, option, identifier, unit price, unit quantity, quantity, subtotal, discount total, tax breakdown, tax total, total и meta, сохраняя также ссылку на purchasable. Это доказательство snapshot data, но не автоматическая гарантия неизменяемости всей записи. [CreateOrderLines](https://github.com/lunarphp/lunar/blob/82f6e4a64585ac5f11ca1fce4b6897be870e691a/packages/core/src/Pipelines/Order/Creation/CreateOrderLines.php).

**Предложение.** Историческое отображение, возвраты и reconciliation не должны зависеть от сегодняшнего каталога или наличия plugin-класса. Accepted financial snapshot должен фиксировать количества, currency/scale, monetary allocations, rule/config revision, shipping/tax basis и версию алгоритма. Fulfillment/payment status остаётся изменяемым lifecycle. Изменение заказа оформляется revision/delta.

## 4. Критерии для будущих прототипов Commerz

Ниже собственные acceptance criteria, а не результаты проведённых тестов.

| ID | Сценарий | Ожидаемое наблюдаемое свойство |
|---|---|---|
| C01 | Два импорта создают одинаковую variant combination | Только одна запись принята, независимо от порядка option labels |
| C02 | Переименование PHP namespace field type | Сохранённые значения продолжают декодироваться либо явно требуют migration |
| C03 | Удаление option, используемого опубликованным variant | Согласованная reject/archive/migrate policy, без висячего выбора |
| C04 | Global → localized attribute | План миграции и измеряемый backlog; fallback не меняет данные скрытно |
| C05 | Изменение двух JSON attributes одновременно | Нет lost update либо конфликт версии виден вызывающему |
| I01 | Два checkout на последнюю единицу | Принят максимум один promise |
| I02 | Несколько каналов с общим warehouse | Суммарные claims не превышают общий supply |
| I03 | Callback оплаты после expiry | Declared compensation/reacquire policy, без незаметного oversell |
| I04 | Duplicate shipment webhook | Единственный inventory effect |
| I05 | Bundle + его компонент в одной корзине | Demand агрегирован до admission |
| I06 | Refund до физического возврата | Physical on hand не увеличивается |
| P01 | Повторная cart calculation | Нет накопления скидок, те же normalized totals |
| P02 | Купон с лимитом 1, два покупателя | Один admission; второй получает business rejection |
| P03 | Повтор того же checkout | Купон расходуется один раз |
| P04 | Shared promotion budget, разные coupon codes | Ограничение соблюдается на общей budget identity |
| P05 | 100 minor units на три равных target | 34/33/33 с определённым stable tie-break, сумма ровно 100 |
| P06 | Частичный возврат после изменения promotion | Используются исходные allocations |
| X01 | Два plugins регистрируют один exclusive strategy | Conflict обнаружен до checkout |
| X02 | Удалён plugin, создавший discount | Старый заказ и refund остаются читаемыми по snapshot |
| X03 | Процесс упал после DB commit до event publish | Outbox доставляет факт после восстановления |
| X04 | Replay устаревшего search event после нового | Projection revision не откатывается |

## 5. Что осталось непроверенным

- Полные current DDL, миграционные цепочки и SQL планы всех проектов.
- Выполнение их тестов и работа на конкретных MySQL/PostgreSQL версиях.
- Точные Sylius priority semantics за пределами прочитанного processor.
- Весь порядок workflow listeners и транзакций Sylius.
- Гарантии наличия денежных rounding policies и query-only stages во всех Lunar extensions.
- Полная устойчивость Bagisto coupon usage к cancellation/refund/plugin scenarios.
- Внутренняя физическая схема БД Shopify: public API её не раскрывает.
- Юрисдикционные налоговые правила: здесь только архитектурная необходимость policy/adapter; правовые рекомендации не давались.
- Производительность предложенного Commerz: никакие численные throughput/SLA пока не подтверждены измерением.

Часть docs.sylius.com при прямом открытии через web tool возвращала redirect в GitBook visitor-auth. Это ограничение retrieval, а не доказательство отсутствия документации. Вместо обхода использованы публичные исходники с commit. Первоначальный адрес docs.lunarphp.io оказался недоступен; действующие проверенные страницы — docs.lunarphp.com/1.x.
