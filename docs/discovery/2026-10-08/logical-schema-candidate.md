# Логическая модель Commerz — каталог сущностей для проверки

2026-10-08. Это **кандидат**, не окончательная физическая БД и не обещание состава v1. Документ фиксирует ответственность сущностей, типы значимых данных и спорные связи. Сравнение альтернатив — [design-hypotheses.md](design-hypotheses.md), определения — [CONTEXT.md](CONTEXT.md).

## 1. Условности

- `identifier` означает стабильный внутренний идентификатор; его физический тип ещё не выбран. `external_reference` — типизированная ссылка на данные хоста/провайдера, не автоматически внешний ключ.
- `decimal` здесь означает **точное** десятичное значение с объявленной точностью, не binary floating point. `money` состоит из exact amount и currency; `quantity` — из exact amount и unit.
- Все mutable records имеют `created_at: timestamp`, `updated_at: timestamp`; optimistic version добавляется там, где есть соответствующая конкурентная операция. Immutable records имеют `created_at` и при необходимости `occurred_at`; `updated_at` не создаёт права редактировать проведённый факт.
- `scope_id` в таблицах означает логическую принадлежность владельцу. Это не решение физически добавить колонку в каждый режим: вариант одной установки, shared tenancy и изолированные пространства должны быть выбраны отдельно.
- `state` ниже — предметное состояние, набор значений которого предстоит завершить. Свободный JSON не заменяет правила переходов.
- `cm_` и другие физические префиксы пока не выбраны. Имена ниже логические.
- Каталог включает потенциальный полный commerce; выделение ядра и optional modules остаётся исследовательской задачей.

## 2. Контур и публикация

| Сущность | Значимые атрибуты и типы | Связи / ограничения |
|---|---|---|
| `commerce_scopes` | id: identifier; key: string; host_owner: external_reference | Логический владелец; может не требовать самостоятельной таблицы в single-owner режиме |
| `stores` | id: identifier; scope_id: identifier; code: string; name: string | Code уникален внутри scope; магазин не определяет физический остаток |
| `channels` | id: identifier; scope_id: identifier; code: string; default_currency: string; default_locale: string | Канал не тождественен складу или tenant |
| `store_channels` | store_id: identifier; channel_id: identifier | Явная m2m; возможно заменить на channel.store_id, если множество владельцев канала не нужно |
| `listings` | id: identifier; product_id: identifier; channel_id: identifier; publication_state: enum; starts_at/ends_at: timestamp? | Уникальность пары product/channel; sellability требует проверки варианта и цены, а не только publication_state |

Не вводить отдельный магазин только для хранения currency/locale, если consumer имеет лишь один sales channel. И наоборот, разные каналы одного бизнеса могут иметь общую номенклатуру и разные коммерческие условия.

## 3. Каталог и типы

| Сущность | Значимые атрибуты и типы | Связи / ограничения |
|---|---|---|
| `product_families` | id: identifier; scope_id: identifier; code: string; revision: bigint | Набор применимых definitions, не обязательно категория навигации |
| `products` | id: identifier; scope_id: identifier; family_id: identifier; code: string; state: enum; revision: bigint | Описание семейства продаваемых конфигураций |
| `variants` | id: identifier; product_id: identifier; code: string; normalized_combination: structured value; combination_digest: string; state: enum; revision: bigint | Комбинация осей уникальна внутри product; стратегия sparse variants |
| `attribute_definitions` | id: identifier; scope_id: identifier; namespace/code: string; semantic_type: string; codec_version: bigint; cardinality: enum; owner_kind: enum; constraints: typed configuration; revision: bigint | Стабильный key; изменение типа при существующих значениях требует явной политики |
| `family_attributes` | family_id: identifier; definition_id: identifier; required: boolean; is_axis: boolean; position: integer | Definition должна быть допустима для соответствующего owner_kind; ось не равна arbitrary property |
| `attribute_options` | id: identifier; definition_id: identifier; code: string; labels: localized text; state: enum | Code уникален внутри definition; label не является identity |
| `variant_selections` | variant_id: identifier; definition_id: identifier; option_id: identifier | Option принадлежит definition; definition — ось этого product family |
| `value_contexts` | id: identifier; scope_id: identifier; channel_id: identifier?; locale: string?; kind: enum | Кандидат на нормализованный scope key; нужен лишь при выбранной многомерной области значений |
| `product_attribute_values` | product_id: identifier; definition_id: identifier; context_id: identifier; payload/state/ordinal: см. ниже | Конкретный owner FK; общий контракт payload с variant values |
| `variant_attribute_values` | variant_id: identifier; definition_id: identifier; context_id: identifier; payload/state/ordinal: см. ниже | Отдельная owner relation исключает неоднозначный polymorphic owner |
| `categories` | id: identifier; scope_id: identifier; parent_id: identifier?; code: string; labels: localized text | Отдельная навигационная классификация; запрет циклов |
| `product_categories` | product_id: identifier; category_id: identifier; position: integer | Явная m2m, scoped integrity |

### Общая структура значения — кандидат typed representation

| Поле | Тип | Смысл |
|---|---|---|
| id | identifier | Идентичность записи или объекта значения |
| definition_id | identifier | Определение и применимый тип |
| definition_revision | bigint | Правила, по которым принято значение |
| context_id | identifier | Явная область; global тоже имеет определённый ключ |
| state | enum: value, clear | Отсутствие записи означает missing; clear останавливает наследование |
| ordinal | integer? | Порядок элемента списка; не нужен для scalar |
| object_id | identifier? | Идентичность элемента compound list для sameObject |
| payload | tagged typed value | String / integer / exact decimal / boolean / date / timestamp / reference / measure / versioned object |
| codec_version | bigint | Версия долговечного формата |
| revision | bigint | Для конкурентного изменения значения/набора |

Это **логический union**, не утверждение, что должна быть одна JSON-колонка или по одной колонке каждого типа. Допустимый payload определяется kind: measurement включает число **и** unit, range — две границы, reference — namespace и ID. Правило «ровно одна непустая колонка вообще» здесь неверно.

Конкурент для этих двух values-таблиц: canonical documents у product/variant и отдельные query facts. Обе реализации одновременно не объявляются независимыми canonical stores.

**Пустая коллекция не равна missing.** Для коллекций нужна самостоятельная logical identity значения, даже когда элементов ноль. Кандидат: `product_attribute_sets` и `variant_attribute_sets` с конкретным owner, definition, context, revision и state=value/clear; элементы ссылаются на свой set. Отсутствующий set = missing; set со state=value и нулём элементов = явно пустой список/набор; state=clear = остановка fallback. В документной реализации это отдельные явно сериализованные состояния. Definition/context элементов должны совпадать с header; header уникален по owner/definition/context. Физический способ ещё не выбран, но ноль element rows без header не выражает требуемую семантику.

### Кандидат `attribute_query_facts`

| Поле | Тип | Смысл |
|---|---|---|
| owner_id / owner_kind | identifier / enum | Владелец производного факта |
| product_id / variant_id | identifier / identifier? | Сохранение sameVariant при поиске |
| definition_id | identifier | Фильтруемое свойство |
| context_id | identifier | Область эффективного значения |
| object_id / ordinal | identifier? / integer? | Принадлежность одному nested object/элементу |
| typed_value | typed scalar/reference | Нормализованное индексируемое представление |
| source_revision | bigint | Версия канонического владельца |
| projector_version | bigint | Версия построения |

Это производная сущность. При объединении разных владельцев в одну карточку их версии учитываются раздельно.

## 4. Учётная номенклатура и рецептура

| Сущность | Значимые атрибуты и типы | Связи / ограничения |
|---|---|---|
| `inventory_items` | id: identifier; scope_id: identifier; code: string; base_unit: string; tracking_policy: enum | Code scoped; наличие кода и tracked mode не зависят от текущего остатка |
| `variant_inventory_requirements` | id: identifier; variant_id: identifier; revision: bigint; fulfillment_kind: enum | Версионированное описание расхода номенклатуры для коммерческого варианта |
| `requirement_components` | requirement_id: identifier; inventory_item_id: identifier; quantity: quantity | Явная m2m с коэффициентом; все количества приводимы к base unit |
| `locations` | id: identifier; scope_id: identifier; code: string; parent_id: identifier?; kind: enum; state: enum | Warehouse/bin/transit не включаются автоматически все в один release; parent graph без циклов |
| `stock_pools` | id: identifier; scope_id: identifier; code: string; availability_policy: typed configuration | Коммерческая область обещания, не копия физического остатка |
| `stock_pool_locations` | pool_id: identifier; location_id: identifier; priority: integer | M2m; пересечение пулов требует единого authority или квот |

Кандидат для простого физического товара: один requirement с одним компонентом quantity=1. Digital/service вариант может не расходовать физическую номенклатуру. Preassembled bundle — собственная номенклатура; virtual bundle расходует компоненты по зафиксированной recipe revision.

В первой версии полезно ограничить requirement components прямыми inventory items. Вложенные рецептуры — отдельный capability с детекцией циклов и предельной глубиной.

## 5. Запасы, резервы и движения

### `stock_resources`

Единица согласованного физического учёта. Возможный способ сделать измерения явно адресуемыми, а не добавлять nullable columns к balance без разбора семантики.

| Поле | Тип | Смысл |
|---|---|---|
| id | identifier | Стабильный адрес ресурса |
| scope_id | identifier | Владелец |
| inventory_item_id | identifier | Учётная номенклатура |
| location_id | identifier | Место |
| dimension_key | string | Каноническая идентичность набора поддерживаемых измерений |
| dimensions | typed structure | Пока только подтверждённые измерения; lot/serial/owner не arbitrary strings |

Альтернатива: без этой сущности, с явными item/location keys у balances. Выбирать по требованию WMS. `dimension_key` сам не обеспечивает существование партии или уникальность серийного номера; нужны конкретные связи/ограничения при включении таких возможностей.

Обязательна уникальность физической идентичности `(scope, inventory_item, location, canonical dimension_key)`, а не только resource ID. Две строки с разными ID не могут обозначать один и тот же ресурс и иметь независимые balances. Concurrent first receipt / первое создание ресурса входит в проверку этого правила; набор dimensions и его canonical key нельзя независимо переинтерпретировать после появления истории.

### `stock_balances`

| Поле | Тип | Смысл |
|---|---|---|
| stock_resource_id | identifier | Ровно один balance на ресурс |
| on_hand | decimal | Физически учтённое количество |
| unavailable_physical | decimal | Часть on_hand, недоступная по физическому состоянию |
| held | decimal | Удержанное количество продаваемой части; не входит в unavailable_physical |
| version | bigint | Версия конкурентного изменения |

Формула для **простого строгого профиля**: `available = on_hand − unavailable_physical − held`. Требования: физически недоступное и held не пересекаются; inbound не входит; safety buffer — отдельно определённая политика, а не ещё одно скрытое вычитание. Backorder и отрицательная корректировка требуют дополнительных правил; универсальной формулой всех складов это не объявляется.

### `stock_movements`

| Поле | Тип | Смысл |
|---|---|---|
| id | identifier | Проведённый неизменяемый факт |
| stock_resource_id | identifier | Затронутый ресурс |
| operation_id | identifier | Общая операция, связывающая несколько legs |
| kind | enum | Приёмка, отгрузка, корректировка, смена физического состояния, leg перемещения |
| delta_on_hand / delta_unavailable_physical | decimal / decimal | Изменения соответствующих компонент |
| reason_reference | typed reference | Причина и исходный документ |
| occurred_at / created_at | timestamp / timestamp | Бизнес-время и время записи |
| correction_of | identifier? | Ссылка на исправляемый факт без его удаления |

Обязательство held ведётся через резервы и их переходы. Если held отражается в movement ledger, kind/суммирование должны исключать двойной учёт. Данный кандидат пока выбирает **отдельную историю резерва**.

### `reservations`, `reservation_lines`, `reservation_transitions`

| Сущность | Поля и типы | Правило |
|---|---|---|
| `reservations` | id: identifier; scope_id: identifier; demand_reference: typed reference; expires_at: timestamp?; state: enum; version: bigint | Demand указывает checkout/order, а не только свободный текст; политика expiry явная |
| `reservation_lines` | id: identifier; reservation_id: identifier; inventory_item_id: identifier; stock_resource_id: identifier?; pool_id: identifier?; requested/remaining/consumed/released: decimal; requirement_snapshot: typed structure | Адресный режим требует resource; пуловый режим требует общего authority и поздней allocation; нельзя смешать оба обещания и посчитать дважды |
| `reservation_transitions` | id: identifier; line_id: identifier; from_version: bigint; transition: enum; quantity: decimal; cause: typed reference; created_at: timestamp | Неизменяемая история consume/release/expire/extend; replay не создаёт повторное изменение |
| `allocations` | id: identifier; reservation_line_id: identifier; stock_resource_id: identifier; assigned/remaining/consumed/released: decimal; state: enum; version: bigint | Только для late allocation; сумма оставшихся назначений не превышает оставшееся удержанное количество |

`requested = remaining + consumed + released` для линии с неизменным первоначальным запросом. Если количество увеличивают, нужны либо новые линии, либо отдельное explicit amendment с новой версией и согласованной формулой. Истечение переводит лишь remaining; consume после expiry должен следовать выбранной политике revalidation, а не использовать stale объект.

Связи также требуют предметной целостности: item линии совпадает с item ресурса; reservation, pool, item и location принадлежат одному scope; resource разрешён выбранным pool по зафиксированной политике membership. Отдельные ссылки на существующие ID этих условий не доказывают.

Для allocation с неизменным исходным назначением `assigned = remaining + consumed + released`; текущая активная назначенная часть — именно remaining. При частичном consume/release меняются согласованно allocation и reservation line. Сумма allocation.remaining не превышает line.remaining. Allocation переносит уже существующее обещание на физический источник, а не создаёт дополнительное удержание. Формула stock_balances выше полностью описывает адресный профиль; пуловый профиль требует отдельно завершить общий authority и протокол переноса pool-held → resource-held без двойного вычитания. До этого I-B не считается законченной схемой.

### Перемещения

| Сущность | Поля и типы | Правило |
|---|---|---|
| `transfers` | id: identifier; scope_id: identifier; source/destination: identifier; state: enum; revision: bigint | Отправка и приёмка могут быть в разные моменты |
| `transfer_lines` | id: identifier; transfer_id: identifier; inventory_item_id: identifier; sent/received/damaged: quantity | Движения связывают source, transit и destination; частичная приёмка сохраняет остаток в пути |

Мгновенная пара source−/destination+ — частный упрощённый режим; физический маршрут с днями в пути требует отдельного состояния/места transit.

## 6. Цены, акции и купоны

| Сущность | Значимые атрибуты и типы | Связи / ограничения |
|---|---|---|
| `price_lists` | id: identifier; scope_id: identifier; currency: string; priority: integer; validity: time interval; state: enum | Явная политика выбора при пересечении |
| `price_entries` | id: identifier; list_id: identifier; variant_id: identifier; minimum_quantity: quantity; amount: exact decimal; revision: bigint | Цена должна иметь currency через list и basis/unit; неоднозначные одинаковые приоритеты не молча last-wins |
| `promotions` | id: identifier; scope_id: identifier; code: string; revision: bigint; validity: time interval; condition_tree: versioned typed structure; actions: versioned typed structure; combination_policy: typed structure | Код правила отдельно от купонного кода; сериализация не PHP closures |
| `coupons` | id: identifier; promotion_id: identifier; normalized_code: string; total_limit: integer?; per_customer_limit: integer?; validity: time interval; state: enum | Правила нормализации/case sensitivity явно заданы; uniqueness scoped |
| `coupon_claims` | id: identifier; coupon_id: identifier; demand_reference: typed reference; customer_reference: external_reference?; expires_at: timestamp; state: enum; version: bigint | Временно удерживает право в quota; все пути применения согласуют общий лимит |
| `coupon_redemptions` | id: identifier; claim_id: identifier?; coupon_id: identifier; order_id: identifier; promotion_revision: bigint; applied_at: timestamp | Состоявшееся применение; способ подтверждения и возврата quota задаётся политикой |

Счётчики usage могут быть материализованы для скорости, но должны согласовываться с фактами. Само наличие таблиц claims/redemptions не гарантирует лимит: нужен атомарный общий write boundary для competing claims, в том числе per-customer.

**Общий лимит акции не принадлежит отдельному купону.** Для budget, разделяемого несколькими кодами, и для automatic promotion без кода нужны дополнительные логические ресурсы:

| Сущность | Поля и типы | Правило |
|---|---|---|
| `promotion_quota_resources` | id: identifier; promotion_id: identifier; kind: enum; coupon_id: identifier?; customer_reference: external_reference?; period_key: string?; limit: count or money; version: bigint | Уникальная canonical identity лимита; общий promotion budget и per-code quota — разные ресурсы; amount budget обязательно имеет currency |
| `promotion_quota_claims` | id: identifier; claim_group_id: identifier; resource_id: identifier; demand_reference: typed reference; units: count or money; state: enum; expires_at: timestamp; version: bigint | Один demand удерживает все требуемые лимиты согласованно; count и amount не смешиваются |

`coupon_claims` в таком варианте — предметная связь купона с общей claim group, не второй независимый расход quota. Promotion без купона получает такую же group. Redemption подтверждает эту группу по policy. Все затрагиваемые quota resources участвуют в одной защищённой операции с определённым порядком; блокировка двух отдельных coupon rows не защищает общий budget. Это кандидат дальнейшего проектирования, конкретный reservation/amendment протокол budgets ещё не реализован.

## 7. Корзина, расчёт и история продажи

| Сущность | Значимые атрибуты и типы | Связи / ограничения |
|---|---|---|
| `carts` | id: identifier; scope/channel: identifier; customer_reference: external_reference?; currency: string; revision: bigint | Изменяемое намерение; reserve mode — явная политика |
| `cart_lines` | id: identifier; cart_id: identifier; variant_id: identifier; quantity: quantity; customization: versioned typed structure | Merge identity не обязательно только variant_id: разные гравировки — разные строки |
| `quotes` | id: identifier; cart_id: identifier?; cart_revision: bigint?; currency: string; evaluated_at/expires_at: timestamp; context_snapshot: typed structure; totals: money components | Определяет применимость расчёта; quote не автоматически договор |
| `quote_lines` | id: identifier; quote_id: identifier; line_reference: typed reference; quantity: quantity; unit_price: money; adjustments/taxes: typed allocations | Сохраняет объяснение расчёта и версии правил |
| `orders` | id: identifier; scope/channel: identifier; order_number: string; customer_reference: external_reference?; accepted_at: timestamp; currency: string; totals: money components; state: enum; version: bigint | Уникальный order_number в объявленном scope; исторические значения не вычисляются заново из каталога |
| `order_lines` | id: identifier; order_id: identifier; variant_reference: identifier?; catalog_snapshot: versioned typed structure; quantity: quantity; unit_price: money; final_total: money; requirement_snapshot: typed structure | Ссылка nullable при допустимом удалении каталога; сохранённый смысл строки не теряется |
| `adjustment_allocations` | id: identifier; order_id: identifier; order_line_id: identifier?; target_kind: enum; source_reference: typed reference; source_revision: bigint; amount: money; allocation_basis: typed structure | Line/order/shipping scope явно различим; сумма распределений соответствует принятому итогу |

Следует отдельно определить immutable accepted snapshot и допустимые amendments заказа. Один mutable `meta` не заменяет протокол исправления истории. Налоговая модель, налоговые документы и расчёт jurisdiction пока не спроектированы; `taxes` обозначает необходимый вход/снимок, не обещание готового налогового движка.

## 8. Исполнение, оплата, возвраты

| Сущность | Значимые атрибуты и типы | Связи / ограничения |
|---|---|---|
| `shipments` | id: identifier; order_id: identifier; state: enum; carrier_reference: external_reference?; dispatched_at: timestamp? | Отдельное состояние от оплаты |
| `shipment_lines` | id: identifier; shipment_id: identifier; order_line_id: identifier; allocation_id: identifier?; quantity: quantity; movement_reference: identifier? | Частичная отгрузка; сумма исполнений не превышает согласованную quantity |
| `payment_attempts` | id: identifier; order_id: identifier; provider_key: string; provider_reference: external_reference?; requested_amount: money; state: enum; idempotency_key: string | Таймаут не равен отказу; reconciliation обязателен для неизвестного результата |
| `payment_operations` | id: identifier; attempt_id: identifier; kind: enum; amount: money; provider_event_reference: external_reference; occurred_at: timestamp | Authorize/capture/void отдельно; duplicate callback не удваивает итог |
| `returns` | id: identifier; order_id: identifier; state: enum; reason: string; revision: bigint | Запрос и приёмка отделены от денежных операций |
| `return_lines` | id: identifier; return_id: identifier; order_line_id: identifier; quantity: quantity; disposition: enum; received_at: timestamp? | Disposition saleable/quarantine/damaged определяет дальнейшее движение |
| `refunds` | id: identifier; order_id: identifier; payment_operation_id: identifier; amount: money; provider_reference: external_reference?; state: enum | Денежная компенсация может не иметь physical return |
| `refund_allocations` | refund_id: identifier; order_line_id: identifier?; original_adjustment_id: identifier?; amount: money; quantity_basis: quantity? | Сохранить связь с исходным округлением и скидкой; не пересчитывать по текущей акции |

Order split по нескольким продавцам, marketplace settlements, subscriptions, gift-card liability и stock valuation ещё не покрыты. Эти области существенно меняют модель и не должны притворяться полем `type` без самостоятельного исследования.

## 9. Служебные записи

| Сущность | Поля и типы | Назначение |
|---|---|---|
| `command_receipts` | scope: identifier; command_kind: string; key: string; payload_digest: string; result: versioned typed structure; state: enum | Уникальный scoped key; иной payload под тем же ключом отклоняется |
| `outbox_messages` | id: identifier; aggregate_kind/id/version: typed reference; event_type/version: string/bigint; payload: structured value; occurred_at: timestamp; delivery_state: enum | Событие записывается вместе с mutation; повтор доставки допустим |
| `inbox_receipts` | consumer_key: string; message_id: string; processed_at: timestamp; outcome: typed value | Дедупликация на стороне обработчика в одной границе с эффектом |
| `external_identity_maps` | source_key: string; entity_kind: string; external_id: string; local_id: identifier; source_revision: string? | Scoped uniqueness, устойчивое сопоставление внешнего объекта |
| `import_runs` | id: identifier; source_key: string; mode: enum; cursor/checkpoint: structured value; state: enum; started_at/completed_at: timestamp | Full snapshot активируется после завершения; неполный импорт не равен удалению отсутствующих |

Retention receipts/inbox/outbox требует отдельного договора: после удаления idempotency record старый retry может стать новой операцией. «Храним ключ» без определения срока не является бессрочной exactly-once гарантией.

## 10. Кардинальности, требующие особого внимания

| Откуда | Тип связи | Куда | Проверка |
|---|---|---|---|
| Product | one-to-many | Variant | Уникальность реальной комбинации осей |
| Variant | many-to-many | InventoryItem | Через requirements/components с версией и количеством |
| InventoryItem | one-to-many | StockResource | Разные места и выбранные измерения |
| StockResource | one-to-one | StockBalance | Один текущий баланс |
| StockResource | one-to-many | StockMovement | История физического состояния |
| StockPool | many-to-many | Location | Нельзя независимо продать общую единицу из каждого пула |
| Reservation | one-to-many | ReservationLine | Все линии согласованы с demand |
| ReservationLine | one-to-many | Allocation | Назначение не создаёт второе удержание |
| Promotion | one-to-many | Coupon | Акция может существовать без купона |
| Coupon | one-to-many | CouponClaim / Redemption | Quota enforcement и replay policy |
| Order | one-to-many | OrderLine | Историческая спецификация и суммы |
| OrderLine | one-to-many | ShipmentLine / ReturnLine | Частичные исполнения и возвраты |
| PaymentAttempt | one-to-many | PaymentOperation | Несколько операций одного платежного workflow |

## 11. Индексы и запросы, которые предстоит доказать

Без выбора физической БД можно сформулировать запросы: variant lookup по нормализованной комбинации; scope/code lookup; active listing по channel; attribute range в одном variant/object; balance by resource; active reservations by expiry; movement timeline по item/location; coupon claims по coupon/customer/state; order lookup по external id; undelivered outbox по порядку готовности.

Для каждого нужны реалистичная cardinality, фильтры по scope и правила пагинации. Уникальность nullable context, case-sensitive code, стабильная сортировка и длинные composite keys должны быть проверены на выбранных хранилищах. Таблица логических полей ещё не является доказательством enforceable constraints.

## 12. Критерии, по которым этот кандидат стоит отвергнуть

- Потребитель с шестью фиксированными сложными полями вынужден создавать десятки definitions и выполнять невыгодные запросы без пользы.
- Inventory не устанавливается без Order или Catalog, хотя требование самостоятельного учёта подтверждено.
- Плагин может обходить основную запись balance или оставляет данные без codec после обновления.
- StockResource/dimensions не удаётся определить без универсального EAV уже для складских ключей.
- Нужен внешний inventory authority, но API обещает локальную атомарность между всеми модулями.
- Сущности включены только «на будущее» и не имеют ни сценария, ни понятного владельца.

Следующий шаг — выбрать несколько вертикальных сценариев и уменьшить модель до достаточного набора. Полный каталог нужен, чтобы видеть будущие ограничения, а не чтобы генерировать все перечисленные таблицы немедленно.
