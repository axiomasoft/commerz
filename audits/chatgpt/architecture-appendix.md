# Commerce: логическая схема и инженерные решения

**Статус:** предлагаемая архитектура, не готовые миграции. Основная среда — модульный монолит с одной транзакционной реляционной БД. Публичный API не должен зависеть от таблиц поискового индекса.

## 1. Обязательные различия

| Понятие | Значение |
|---|---|
| Product | Карточка и общее описание товара |
| ProductVariant | Конкретная продаваемая конфигурация/SKU |
| InventoryItem | Физически учитываемый ресурс в базовой единице |
| Store | Логический магазин/витрина/бренд |
| SalesChannel | Контекст публикации и политики продажи |
| Warehouse | Физический источник запасов |
| StockPool | Необязательная логическая группа источников; не дополнительный физический остаток |
| Attribute | Определение пользовательского свойства |
| Variant axis | Свойство, определяющее различие продаваемых вариантов |
| Family | Схема наполнения карточки и обязательности полей |
| Category | Навигационная/мерчандайзинговая классификация |
| Price | Коммерческое условие продажи, не произвольное свойство |
| Listing | Публикация товара в канале, не факт наличия |
| OfferView | Рассчитанное предложение в контексте канала, цены и исполнения |

Простой товар также имеет один вариант. Услуга может иметь вариант без связи со складской позицией. Обычный физический вариант требует одну складскую позицию; упаковка — несколько единиц той же позиции; виртуальный комплект — несколько разных позиций. По умолчанию не допускаются рекурсивные требования «вариант требует другой вариант»: требования ссылаются на InventoryItem.

### Повторное использование кода не означает общую базу данных

Один Composer-пакет можно установить в десять независимых проектов. Это не создает общий каталог или арбитраж остатков между их базами. Если два независимых приложения продают один физический запас, нужны единый Inventory authority/API, общая транзакционная область складского учета либо заранее выделенные непересекающиеся квоты. Периодический импорт одного остатка из 1С в две БД не гарантирует отсутствие двойной продажи.

Для первого варианта архитектуры предполагается, что магазины с общим запасом находятся в одной установке Commerce. Независимые установки по умолчанию имеют независимые данные. Централизованный складской сервис вводится при действительной необходимости совместного запаса, а не как обязательная микросервисная платформа с первого релиза.

## 2. Границы распространения и зависимости

Репозиторий может быть монорепозиторием. Composer-пакеты выделяются по реальным границам повторного использования, а не по каждой таблице.

| Пакет | Владеет | Допустимые зависимости |
|---|---|---|
| support | Общие значения, часы, протокол событий, базовые технические контракты | PHP и небольшие библиотеки точной арифметики |
| attributes | Определения, версии схем, узлы, справочники, нормализация | support |
| stores | Магазины, каналы, контексты | support |
| catalog | Карточки, варианты, оси, категории, семейства, значения свойств | support, attributes, stores |
| inventory | Склады, единицы учета, балансы, документы, движения, резервы | support |
| pricing | Прайс-листы, цены, правила разрешения цены | support, catalog, stores |
| search | Контракт поиска и SQL-проекции | Контракты чтения перечисленных модулей |
| integrations | Подключения, внешние идентификаторы, staging, журнал импорта | support |
| commerce | Композиция, связывающие модели, фасад сценариев | Перечисленные модули |
| integration-onec | Транспорт CommerceML, отображение сущностей, профиль обмена | integrations, публичные API необходимых модулей |
| http-api / admin | Контроллеры, авторизация представлений, UI | commerce |
| search-elasticsearch | Адаптер конкретного поискового движка | search |

Не требуется, чтобы catalog устанавливался без stores: автоматически созданный канал по умолчанию допустим. Требуется, чтобы каталог не писал напрямую в баланс inventory, а inventory не зависел от внутреннего Product.

Связывающие таблицы `variant_inventory_requirements`, `channel_warehouses`, `channel_inventory_policies` принадлежат композиции commerce. Удаление одного пакета из установленной системы с данными — отдельная процедура, а не автоматический cascade drop.

Зависимости на уровне Composer проверить прототипом. При чтении сложных витринных данных допустимы специализированные SQL-проекции; запрещать все JOIN между модулями и заменять их N+1-вызовами ради архитектурной чистоты не следует.

## 3. Общие соглашения по БД

Имена ниже без префикса для читаемости; реальный префикс выбирается один раз перед выпуском. Не обещать произвольную смену имен таблиц после установки.

Рекомендуемый простой вариант ключей: внутренний `BIGINT` PK и отдельный уникальный публичный идентификатор для внешне адресуемых сущностей. Другой единый вариант (например, ULID как PK) допустим, но должен быть выбран до первых миграций; не поддерживать все варианты ключей одновременно без необходимости.

- Все важные внутренние отношения имеют реальные FK. SKU, GTIN и идентификатор 1С не являются PK.
- Критические количества и цены не используют FLOAT/DOUBLE. Примеры масштаба: quantity DECIMAL(20,6), attribute numeric DECIMAL(24,8). Это проектные значения, а не универсальный диапазон для любого бизнеса.
- Для дат событий различаются `effective_at` и `recorded_at`. Время хранится с договоренностью UTC; бизнес-дата без времени — отдельный тип.
- Колонки `version` служат оптимистической конкуренции и версиям снапшотов.
- Денежные суммы передаются в API строками/объектами, не JSON float. Цена единицы и итог к оплате могут иметь разный масштаб и разные правила округления.
- Уникальность области видимости не строится только на nullable-комбинациях. Используется обязательный нормализованный `scope_key` или FK на него.
- Частичное удаление soft-delete не заменяет явные бизнес-статусы. Исторические складские документы не исчезают при архивировании товара.
- Для SaaS `tenant_id` включается в соответствующие уникальные ключи, FK, события, блокировки, кэши и внешние отображения. Это отдельная редакция схемы, а не поздно добавляемый global scope.

## 4. Таблицы stores и связей

| Таблица | Основные поля | Ключевые ограничения |
|---|---|---|
| stores | id, public_id, code, name, status | UNIQUE(code), UNIQUE(public_id) |
| sales_channels | id, store_id, code, status, default_locale, default_currency, policy_version | FK store; UNIQUE(code) в установке |
| channel_locales | channel_id, locale_code, fallback_order | UNIQUE(channel_id, locale_code) |
| channel_domains | id, channel_id, normalized_host, path_prefix | Уникальный маршрут; владение доменом проверяется приложением |
| channel_warehouses | channel_id, warehouse_id, priority, enabled, fulfillment_modes | UNIQUE(channel_id, warehouse_id); два FK; не хранить остаток |
| channel_inventory_policies | channel_id, reservation_mode, split_policy, backorder_policy, allocation_strategy | Версионируемая семантика политики |
| product_channels | product_id, channel_id, publication_state, visible_from, visible_until | UNIQUE(product_id, channel_id) |
| variant_channels | variant_id, channel_id, enabled_override | Необязательное ограничение вариантов, не дублирование карточек |

Публикация, наличие, наличие цены и возможность доставки — разные условия. Политика может публиковать отсутствующие товары, но не разрешать их покупку.

Один физический склад, связанный с двумя каналами, остается одним физическим складом. Если бизнес резервирует квоты каналам, квоты — дополнительное ограничение поверх общего физического запаса, а не его копия.

## 5. Таблицы catalog

| Таблица | Основные поля | Ключевые ограничения |
|---|---|---|
| product_families | id, code, title, schema_policy_version | UNIQUE(code) |
| family_attribute_groups | id, family_id, code, position | Это группы формы, а не составные значения |
| family_attributes | family_id, attribute_id, group_id, target, required_policy, position | UNIQUE(family_id, attribute_id, target) |
| products | id, public_id, code, family_id, lifecycle_state, version | FK family; уникальные стабильные идентификаторы |
| product_translations | product_id, locale_code, name, description | UNIQUE(product_id, locale_code) |
| product_variants | id, public_id, product_id, sku, state, combination_signature, version | UNIQUE(product_id, combination_signature); политика уникальности SKU |
| product_variant_axes | id, product_id, attribute_id, position | UNIQUE(product_id, attribute_id) |
| variant_option_values | variant_id, axis_id, option_id | UNIQUE(variant_id, axis_id); принадлежность варианту/продукту/справочнику проверяется |
| categories | id, parent_id, code, position, version | FK parent; запрет циклов в операции перемещения |
| category_translations | category_id, locale_code, name, slug | Уникальность slug определяется маршрутом/каналом, не только языком |
| category_closure | ancestor_id, descendant_id, depth | UNIQUE(ancestor_id, descendant_id); восстановимая проекция |
| product_categories | product_id, category_id, position, is_primary | UNIQUE(product_id, category_id); отдельная политика главной категории |
| channel_category_roots | channel_id, category_id | Определяет навигационные корни канала |
| category_filter_configs | category_id, scope_id, attribute_id, field_code, presentation, selection_policy, position | Настройки фасетов категории/канала; проверка поля при смене активной схемы |
| product_relations | source_product_id, target_product_id, relation_type, position | Два FK; направленность и уникальность зависят от типа |
| product_media / variant_media | owner_id, media_id, role, position, locale_code | Медиахранилище через адаптер; реальные ссылки внутри выбранного media-модуля |
| product_aliases | product_id, old_code, external_namespace | История переименования/сопоставления при необходимости |

### Варианты и подпись комбинации

Каноническая запись осей — только `variant_option_values`. Унифицированный API свойств и поисковая проекция могут представлять ее как обычные значения, но это не второй независимо редактируемый источник.

Подпись рассчитывается из отсортированного набора стабильных идентификаторов `(axis, option)`, а не локализованных названий. При хешировании хранится или может быть восстановлена каноническая комбинация для проверки коллизии. Изменение осей существующего товара — миграционный сценарий с проверкой дубликатов и сохранением связи старых SKU с запасами/заказами.

Не генерировать и не публиковать автоматически все элементы декартова произведения. Допустим отдельный помощник генерации черновиков реально нужных комбинаций.

## 6. Схемы свойств и значения

### 6.1. Реестр определений

| Таблица | Основные поля | Смысл |
|---|---|---|
| attributes | id, namespace, code, label, lifecycle_state, active_schema_version_id | Стабильная идентичность свойства |
| attribute_schema_versions | id, attribute_id, version, state, published_at | Опубликованные семантические версии неизменяемы |
| attribute_schema_nodes | id, schema_version_id, parent_node_id, field_code, kind, cardinality, constraints, dictionary_id, measurement_family | Каноническая нормализованная схема, включая простые и составные значения |
| attribute_presentations | attribute_id, locale_code, label, help_text, widget, ui_options | Представление, не источник семантического типа |
| dictionaries | id, code, lifecycle_state | Повторно используемый справочник |
| dictionary_options | id, dictionary_id, code, position, state, metadata | UNIQUE(dictionary_id, code) |
| dictionary_option_translations | option_id, locale_code, label | UNIQUE(option_id, locale_code) |
| attribute_value_scopes | id, scope_key, channel_id, locale_code | UNIQUE(scope_key); scope_key NOT NULL; global имеет собственную запись |

`attribute_schema_nodes` — источник структуры; JSON-схема для UI/API экспортируется из него. Не разрешать независимо редактировать обе формы. Constraints могут быть JSON, но проходят типизированный валидатор и ограниченный DSL; нельзя хранить исполняемый PHP/SQL из формы администратора.

Scope принадлежит catalog и может ссылаться на stores. Примеры ключей: `global`, `locale:ru`, `channel:<public-id>`, `channel:<public-id>/locale:ru`. Кодирование ключа каноническое, не произвольная конкатенация непроверенного ввода. При переименовании channel.code ключ не меняется, потому что использует стабильный идентификатор.

### 6.2. Основные типы

Тип и кратность ортогональны. Базовые типы: keyword, text, integer, decimal, boolean, date, datetime, option, measurement, reference, file. Кратность: single, set, ordered_list. Структурные типы: object и table.

У measurement обязательны совместимая семья единиц, точность, базовая единица и политика преобразования. Например, длина не сравнивается с массой. Значения `10 cm` и `100 mm` имеют один канонический числовой смысл; исходная единица может сохраняться для редактирования.

Color — это типизированный справочник/значение с кодом, переводом и swatch. Size — справочник с порядком либо измерение в определенной системе; не произвольная строка, к которой автоматически применяется числовая сортировка.

Для безопасности и предсказуемости в первом релизе ограничить вложенность: скаляры, наборы, одиночный объект и один уровень таблицы объектов. Не обещать произвольную рекурсивную структуру с неограниченной глубиной.

### 6.3. Каноническое хранение значений

Раздельные семейства таблиц обеспечивают реальные FK на владельцев:

| Таблица | Поля |
|---|---|
| product_attribute_values | id, product_id, attribute_id, scope_id, schema_version_id, value_state, revision |
| product_attribute_groups | id, value_id, group_key, parent_group_id, schema_node_id, ordinal |
| product_attribute_atoms | id, value_id, group_id, schema_node_id, ordinal, kind, typed payload |
| variant_attribute_values | Аналогично, но FK variant_id |
| variant_attribute_groups | Аналогично |
| variant_attribute_atoms | Аналогично |

`typed payload`: `value_text`, `value_integer`, `value_decimal`, `value_boolean`, `value_date`, `value_datetime`, `option_id`, `measurement_unit_code`, `measurement_base_value`. Для файлов/ссылок предпочтительны расширяющие типизированные связи с реальными FK, а не произвольная пара `model_type/model_id` для критических отношений.

Обязательные ограничения:

1. UNIQUE(owner_id, attribute_id, scope_id) — одна актуальная версия значения в области.
2. Версия схемы принадлежит attribute_id; узел принадлежит этой версии.
3. Группа принадлежит тому же value_id; атом не может сослаться на группу другого владельца.
4. UNIQUE(value_id, group_key), UNIQUE(group_id, schema_node_id, ordinal).
5. CHECK типа допускает только соответствующий payload. Для measurement одновременно допускаются число и единица по своему правилу; наивное «ровно одна непустая колонка» для всех типов неверно.
6. single имеет один атом; set не допускает повторяющиеся нормализованные значения; ordered_list допускает/запрещает повторения по явному правилу.
7. option принадлежит разрешенному dictionary; это подкрепляется схемой FK там, где возможно, и транзакционным валидатором.
8. Для скаляра используется корневая группа. Для каждой строки table — свой стабильный group_key.
9. `value_state = clear` запрещает дочерние полезные значения. Отсутствие заголовка означает отсутствие переопределения; пустой set отличается от clear и от отсутствия.
10. Старые опубликованные версии схемы сохраняются, пока на них ссылаются значения/история.

Одинаковую техническую реализацию схемы product/variant можно генерировать общими миграционными helpers; это не требует общего `entity_type/entity_id`, лишенного FK.

### 6.4. Fallback и наследование

Это проектная политика, не универсальная истина. Предлагаемый порядок для локализуемого и канально-зависимого свойства:

`channel+requested_locale → global+requested_locale → channel+fallback_locale → global+fallback_locale → channel-neutral → global-neutral`.

Для нелокализуемых и неканальных свойств недопустимые уровни отсутствуют. Разрешение должно возвращать не только value, но и provenance: исходный scope, schema_version, inherited/explicit.

Нельзя незаметно объединять массивы разных уровней. По умолчанию объект/набор переопределяется целиком. Patch/merge — отдельная явно включаемая семантика с тестами для удаленных полей и строк.

### 6.5. Составное свойство — четыре разных случая

- Группа формы: только UI, например «Габариты и вес».
- Object: одно значение из нескольких полей, например dimensions.width/height/depth.
- Table: несколько связанных строк, например memory_modules[{standard, capacity}].
- Reference entity: ссылка на общий объект, например производитель с логотипом и страной.

Не следует представлять все четыре случая одной строкой JSON без определения поведения фильтра и жизненного цикла.

## 7. Поиск и индексы

Источник истины — catalog/attributes/pricing/inventory. Поисковые таблицы можно удалить и восстановить.

| Проекция | Содержимое |
|---|---|
| catalog_product_read | Карточка и ее публикация/локализованные отображаемые данные |
| catalog_variant_read | Вариант, активность, product_id, проекция осей |
| catalog_attribute_number_index | owner/product/variant, attribute/field, scope, group_key, canonical decimal |
| catalog_attribute_term_index | То же, option_id/normalized keyword |
| catalog_attribute_date_index | То же, native date/datetime |
| catalog_attribute_boolean_index | То же, boolean |
| catalog_price_index | variant, price-list/segment, currency, quantity tier, validity, resolved amount |
| catalog_availability_index | item/variant, scope политики доступности, quantity/статус, source_version |
| search_generations | generation, schema_version, state, high_watermark |
| projection_checkpoints | projector, stream/partition, checkpoint |

Не разворачивать без необходимости каждое свойство в произведение channel × locale × currency × price-list × warehouse × customer. Контент, цены и наличие имеют разные размерности и разные причины инвалидирования.

### Контракт фильтров

`sameVariant(allOf(color=red, size=M, sellable=true, price<=200))` — все условия удовлетворяет один вариант.

`sameObject(memory_modules, allOf(standard=DDR5, capacity>=16))` — оба условия удовлетворяет одна строка составного значения.

`anyOf(red, blue)` внутри одного фасета отличается от `containsAll(tag_a, tag_b)`. Пустой фильтр — отсутствие ограничения, а не поиск пустого значения. Операторы exists, isEmpty, isMissing, equals(false) различаются.

Для списка товаров фасеты по умолчанию считают DISTINCT product_id. Для списка SKU — DISTINCT variant_id. При self-exclusion снимается только ограничение текущего фасета; канал, публикация, другие фильтры и связность SKU остаются.

Сортировка multivalue запрещена без reducer или явного словарного порядка. Цена сортировки берется из подходящих вариантов и выбранного контекста, а не из любого самого дешевого варианта товара.

### Индексы и ограничения

Стартовые кандидаты: `(scope_id, attribute_id, normalized_value, variant_id)` для фильтра и `(variant_id, attribute_id)` для сборки значений. Это не окончательная спецификация: порядок колонок проверяется EXPLAIN и реальным распределением данных.

keyword имеет ограничение длины/нормализации; полнотекстовое описание не индексируется как безразмерный точный термин. Числовой индекс хранит числа, не строковое приведение на каждой выборке.

Elasticsearch-адаптер должен сохранять nested-корреляцию, где она нужна, и отдельно проверять точность/семантику distinct facet count. Наличие API facets у движка не доказывает поддержку заданного контракта.

SQL-адаптер — проверяемая базовая реализация. Ограниченные адаптеры обязаны явно описывать supportsSameVariant, supportsSameObject, supportsDistinctProductFacets, exactCounts, supportedSortReducers; не подменять поиск приблизительно похожим результатом молча.

## 8. Inventory

### 8.1. Канонические таблицы

| Таблица | Основные поля | Ограничения |
|---|---|---|
| warehouses | id, public_id, code, state, timezone, metadata | UNIQUE(code) |
| inventory_items | id, public_id, code, base_unit, quantity_scale, tracking_mode, version | Базовая единица не меняется незаметно после движений |
| variant_inventory_requirements | variant_id, inventory_item_id, required_quantity, recipe_version | UNIQUE(variant_id, inventory_item_id); qty > 0 |
| inventory_balances | item_id, warehouse_id, on_hand, unavailable, reserved, safety_stock, version | UNIQUE(item_id, warehouse_id); reserved/unavailable/safety >= 0 |
| stock_documents | id, public_id, document_type, state, external_ref, effective_at, recorded_at, posted_at, reversal_of_id | Опубликованные документы не редактируются напрямую |
| stock_document_lines | id, document_id, item_id, from_warehouse_id, to_warehouse_id, quantity, unit_conversion_snapshot | qty > 0; разрешенные стороны зависят от типа документа |
| stock_ledger_entries | id, document_line_id, item_id, warehouse_id, on_hand_delta, unavailable_delta, effective_at, recorded_at, sequence | Append-only; уникальная запись стороны проведения |
| inventory_reservations | id, public_id, owner_reference, state, expires_at, policy_version, version | Собственный жизненный цикл без обязательной зависимости от Order-модели |
| reservation_allocations | id, reservation_id, item_id, warehouse_id, allocated_quantity, shipped_quantity, released_quantity | shipped + released <= allocated |
| reservation_events | id, reservation_id, allocation_id, event_type, quantity, command_id, occurred_at | Идемпотентность событий/команд |
| inventory_discrepancies | id, item_id, warehouse_id, detected_at, kind, details, resolution_state | Разбор недостач/несовпадений |
| inventory_count_sessions | id, warehouse_id, state, cutoff_policy, counted_at | Необязательная оболочка инвентаризации |

Таблица balances — синхронный текущий агрегат. Журнал физического движения позволяет сверку/восстановление; overlay резервов восстанавливается из активных распределений и их журнала, а не из физического ledger. Обновление баланса, запись движения/резерва и outbox — одна локальная транзакция.

Складские измерения owner/lot/bin/serial/quality не добавляются полупустыми колонками «на всякий случай». Для WMS вводится InventoryAccount с согласованным уникальным ключом измерений и миграцией агрегирования; либо отдельные реальные таблицы детализации. Это требует собственного ADR и тестов.

### 8.2. Формула

```
available(item, warehouse) = max(0, on_hand - unavailable - reserved - safety_stock)
```

`reserved` включает все активные типы удержания, без повторного вычитания confirmed/committed. `incoming` не включается в физически доступное количество.

Суммирование нескольких складов возможно только при разрешенном распределении заказа. Для выдачи из одного магазина/сборки комплекта на одном складе функция другая. Запасы по одной и той же warehouse-id не суммируются дважды из-за разных путей через pool.

Политика отрицательного физического остатка/backorder задается отдельно. Для строгого режима физического учета можно запретить on_hand < 0, но нельзя навязать универсальное on_hand >= reserved: инвентаризация может выявить недостачу при уже существующем обязательстве.

### 8.3. Команды

| Команда | Физический эффект | Резерв |
|---|---|---|
| ReceiveStock | on_hand += q | Не меняется |
| PlaceReservation | Не меняется | reserved += q |
| ConfirmReservation | Не меняется | Меняется состояние, не повторное +q |
| ReleaseReservation | Не меняется | reserved -= оставшаяся освобождаемая часть |
| ShipReservedStock | on_hand -= q | reserved -= q |
| ReturnStock | По факту приемки, возможно сначала unavailable += q | Старый резерв автоматически не восстанавливается |
| DispatchTransfer | Уменьшает исходный доступный склад, помещает в transit | Зарезервированное нельзя безусловно перемещать |
| ReceiveTransfer | Уменьшает transit, увеличивает целевой склад | Только фактически принятая часть |
| ApplyStockCount | Согласованная корректировка физической истины | Сохраняет обязательства, открывает discrepancy при недостаче |
| AssembleKit | Списывает компоненты, приходует отдельную kit-позицию | По отдельной политике |

Проведенный документ исправляется сторно/компенсирующим документом. Причина, пользователь/система, idempotency key и исходный документ сохраняются.

### 8.4. Атомарное резервирование

Псевдо-SQL, не полная production-операция:

```sql
UPDATE inventory_balances
SET reserved = reserved + :quantity,
    version = version + 1
WHERE item_id = :item_id
  AND warehouse_id = :warehouse_id
  AND on_hand - unavailable - reserved - safety_stock >= :quantity;
```

Обязательные условия вокруг него: quantity > 0, decimal без float, установленный шаг количества, корректная channel/warehouse-политика, идемпотентность команды, транзакция всех строк/компонентов, проверка affected rows, запись allocations/events/outbox, ограниченный повтор всей транзакции при deadlock/serialization failure.

При нескольких строках блокировки берутся в детерминированном порядке `(warehouse_id, item_id)` или другой единой глобальной сортировке. Расчет предварительного плана распределения вне транзакции не является обещанием остатка: внутри происходит окончательная проверка/перепланирование.

Истечение резерва и его подтверждение блокируют один reservation aggregate. Пока истекший резерв не освобожден транзакционно, консервативно удерживать его в reserved, а не давать поиску/чекауту разные определения активности.

Изменение политики привязки склада к каналу не должно создавать гонку с уже начинающейся операцией: применить version check или согласованное чтение/блокировку политики. Обязательства уже созданных резервов сохраняются до явного переноса/отмены.

### 8.5. Комплекты

Для pooled-комплекта с независимым распределением:

`capacity = min_i floor(available_i / required_i)`.

Резерв/заказ фиксирует фактический состав требований, версию рецепта и преобразование единиц. После изменения состава комплекта существующее обязательство не пересчитывается по новой карточке. До расчета повторные требования к одному и тому же InventoryItem агрегируются. При требовании сборки на одном складе capacity определяется по каждому допустимому складу и правилам исполнения: `sum_w min_i floor(available_i,w / required_i)` — для количества комплектов, каждый из которых собирается на одном складе и может отправляться отдельной посылкой. Для заказа, который целиком должен уйти с одного склада, применяется максимум по складам, а не эта сумма.

Предсобранный комплект — самостоятельный InventoryItem с реальным запасом. Нельзя одновременно считать уже списанные на него компоненты свободными для виртуальных комплектов.

## 9. Pricing

| Таблица | Основные поля |
|---|---|
| price_lists | id, code, currency, tax_basis, priority, state |
| price_list_channels | price_list_id, channel_id |
| price_list_segments | price_list_id, segment_key |
| prices | id, variant_id, price_list_id, amount, min_quantity, unit, valid_from, valid_until, version |
| price_change_log | price_id, old/new values, actor, command_id, occurred_at |

Контекст разрешения цены: channel, currency, customer segment, quantity, moment, unit, tax display basis. Пересекающиеся активные интервалы/приоритеты либо запрещаются, либо разрешаются однозначным документированным правилом. Случайный первый Eloquent-result недопустим.

У цены за единицу возможны дробные значения меньше минимальной денежной единицы. Итог к оплате округляется отдельно. Выбранная библиотека точной арифметики скрывается за собственным стабильным Money/Price/Quantity API.

Персональные купоны, корзинные скидки, налоги конкретной юрисдикции и платежи не обещаются в минимальном pricing-модуле. Поисковая сортировка должна честно называть используемый тип цены и не выдавать приблизительную публичную цену за окончательный checkout-total.

## 10. Надежность событий и импорта

| Таблица | Поля/назначение |
|---|---|
| command_receipts | scope, command_type, idempotency_key, payload_hash, status, result_reference, created_at; UNIQUE(scope, command_type, idempotency_key) |
| outbox_messages | event_id, aggregate_type/id, aggregate_version, event_type/version, payload, occurred_at, attempts, available_at, published_at |
| inbox_receipts | consumer, event_id, processed_at; UNIQUE(consumer, event_id) |
| integration_connections | id, code, adapter, profile_version, encrypted_credentials_reference, state |
| external_identities | connection_id, external_entity_type, external_scope, external_id, internal_entity_type/id; уникальная внешняя четверка |
| integration_sessions | connection_id, session_key, direction, phase, state, source_revision, started_at, completed_at |
| integration_files | session_id, logical_name, safe_storage_key, size, checksum, state |
| import_batches | session_id, mode, scope, expected_revision, checkpoint, state |
| import_records | batch_id, external_identity, operation, payload, validation_state, applied_revision, error |
| import_errors | batch/record, error_code, safe_details, retryability |
| source_checkpoints | connection_id, entity_scope, applied_source_revision, completed_snapshot_id |
| entity_source_ownership | connection_id, entity_scope/field_group, authority_policy |

Полиморфная ссылка допустима на границе интеграций как отображение внешних сущностей, но не как оправдание отказа от FK во всех внутренних доменах. Для строгой целостности можно использовать отдельные typed mapping-таблицы.

Outbox дает атомарную фиксацию намерения отправить событие вместе с изменением БД. Потребитель должен быть идемпотентным: доставка может повторяться. `afterCommit` без outbox не закрывает окно падения между commit и отправкой.

В событии должны быть event_id, event_type, schema_version, aggregate_id, aggregate_version/sequence, causation_id, correlation_id, occurred_at и достаточно данных для оговоренного способа обработки. Не сериализовать Eloquent-модель целиком как публичный контракт.

Снапшот версии 12 может заменить снапшот 11; пришедшую позднее версию 10 можно отбросить. Для событий-дельт пропуск версии 11 может потерять движение: необходимы последовательность, обнаружение дыр и повторное чтение/восстановление, а не универсальное `ignore older`.

### 10.1. 1С: два разных интерфейса

CommerceML — модель данных/XML. Протокол обмена с сайтом — транспортные стадии авторизации, инициализации, передачи файлов и импорта. Оба изолируются в адаптере; внутренний Product не превращается в XML-элемент.

Поток: прием безопасного файла → quarantine/raw archive → streaming parsing → staging → mapping/versioned profile → validation → идемпотентные команды небольшими батчами → outbox → проекции → отчет.

Нельзя обещать универсальную идемпотентность каждого legacy-файлового чанка по одному хешу: одинаковые блоки могут быть легитимными. Там, где транспорт не дает устойчивой последовательности чанков, применяются подтвержденные правила сессии/перезапуска; доменная идемпотентность устанавливается на уровне сущностей/документов/батчей.

### 10.2. Источник истины

Пример политики, которую нужно согласовать с конкретным проектом:

| Данные | Предполагаемый владелец |
|---|---|
| Номенклатура, стабильные коды, физические склады | 1С |
| Физический остаток | Только одна явно выбранная система |
| Маркетинговые названия, SEO, витринные категории | Commerce или ручная политика по полям |
| Розничный/оптовый прайс | 1С по отображению типов цен |
| Временные онлайн-резервы | Commerce с протоколом передачи/подтверждения |
| Статус исполнения заказа | По согласованной машине состояний, не last-write-wins |

Если 1С присылает уже свободный остаток с вычетом отправленных ей заказов, повторное вычитание локальных резервов даст недопродажу. Если 1С присылает старый физический остаток, его безусловная установка может вернуть уже проданный товар. Нужны gross-vs-net определение, source revision/checkpoint, признанные другой стороной заказы/резервы и правила сверки.

Одни timestamps/CAS не восстанавливают отсутствующую причинную связь между снимком и событиями. При недостаточном протоколе необходимо выбрать консервативную политику/окно синхронизации либо отдельный эксклюзивный запас; не обещать математически невозможный exactly-once между независимыми базами.

### 10.3. Full / delta

Отсутствующий элемент в delta ничего не удаляет. Отсутствие поля обычно сохраняет старое значение; explicit clear — другая операция. Деактивация отсутствующих записей допустима только после успешного полного снимка и только в его точном source/scope. Частично неуспешный импорт не запускает массовое удаление «не встреченных» товаров.

Внешний id остается непрозрачной строкой, включая комбинированные предложения. Парсинг разделителей допускается только в конкретном профиле, подтвержденном реальными fixtures.

## 11. Публичный API и расширение

API должен разделять команды и чтение, но не обязательно вводить отдельный message bus на каждую операцию.

Предлагаемые контракты:

- CatalogWriter, CatalogReader;
- AttributeTypeRegistry, AttributeValidator, AttributeNormalizer;
- VariantCombinationPolicy;
- InventoryService, AllocationStrategy, AvailabilityProvider;
- PriceResolver;
- SearchService, SearchCapabilities, FilterCompiler;
- IntegrationMapper, SourceOwnershipPolicy;
- ActorResolver, Clock, IdempotencyStore, OutboxPublisher.

Запись идет через команды и их транзакционные обработчики. Для чтения Eloquent-отношения/специализированные запросы допустимы. Сырые массовые записи в чужие таблицы не являются публичным интеграционным API.

SalesContext передается явно: channel, locale, currency, segment, fulfillment context, moment. Не полагаться на глобальный изменяемый singleton текущего магазина в очередях/долго живущих воркерах.

Extension points: новые типы свойств, валидаторы, вычисляемые read-only поля, алгоритмы распределения складов, резолверы цены, адаптеры источников, авторизация, медиа, поиск. Переопределение базовой Eloquent-модели/наследование допускается ограниченно, но не является единственным способом расширения.

Определение нового типа свойства включает normalize, validate, serialization, operators, index representation и migration policy. Генерация SQL относится к backend-адаптеру; пользовательский тип не должен бесконтрольно вставлять SQL в запрос.

Вычисляемые свойства требуют графа зависимостей без циклов, известных причин инвалидирования и декларации deterministic/indexable. Исполнение произвольного PHP/SQL, заданного пользователем через UI, в ядро не включается.

## 12. ADR: решения и альтернативы

| ADR | Предлагаемое решение | Отклоненная/отложенная альтернатива | Проверка |
|---|---|---|---|
| 01 | Модульный монолит, одна транзакционная БД | Микросервисы с распределенным резервом с первого дня | Сквозная транзакция и отказоустойчивость |
| 02 | Product / Variant / InventoryItem | Остаток прямо в products | Упаковка, комплект, общий ресурс |
| 03 | Всегда sellable variant | Две несвязанные ветки simple/configurable | Простая карточка проходит тот же API |
| 04 | Фиксированные поля + typed EAV | Все строкой или все домены в EAV | Числа, справочники, FK, планы SQL |
| 05 | Один канонический источник значений | Два редактируемых JSON/табличных источника | Повторное построение проекций |
| 06 | Bounded composite + stable group identity | Неограниченный JSON без корреляции | sameObject и изменение схемы |
| 07 | Нет независимого остатка по магазину | Копировать баланс в каждый channel | Последняя единица при двух каналах |
| 08 | Резерв на физических источниках в v1 | Только логический pool без общего арбитража | Пересекающиеся пулы |
| 09 | Физический ledger + синхронный balance + reserve overlay | Универсальный event sourcing всех моделей | Сверка, восстановление, partial shipment |
| 10 | SQL-поиск как эталон; другие адаптеры по capabilities | Все движки «взаимозаменяемы» | Семантическая эквивалентность |
| 11 | Outbox + идемпотентный consumer | Только afterCommit/listener | Падение после commit, повтор доставки |
| 12 | 1С за staging и source-ownership | XML напрямую в Eloquent и last-write-wins | Full/delta/replay/stale snapshot |
| 13 | Composition/strategies для расширения | Класс на каждый тип магазина, универсальный BaseModel | Подключение нового типа без форка |
| 14 | Точный decimal и явная единица | Float и unit как необязательная строка | Пакеты, доли, преобразования |
| 15 | Мультимагазинность отдельно от multitenancy | Tenant scope как поздний security patch | Межтенантные FK/кэш/очереди |
| 16 | Поиск eventual; резерв строго по источнику | Поисковый индекс подтверждает остаток | Задержка индекса не дает oversell |
| 17 | Immutable schema versions, expand/backfill/switch | Изменение типа in-place без миграции | Старые значения и повторная индексация |
| 18 | Core без обязательного admin/framework UI | Жесткая связка с Filament/Vue | Headless-проект использует только API |
| 19 | Общая физическая истина требует общего арбитража между установками | Две независимые БД получают одинаковый свободный остаток | Конкуренция двух отдельных приложений за общую единицу |

СУБД и tenancy остаются обязательными входными ADR перед кодом. Для собственного нового проекта разумный кандидат PostgreSQL. Для максимально широкого Laravel-пакета необходимо учитывать MySQL, но заявлять поддержку только после полной CI-матрицы поведения, а не успешной установки миграций.

## 13. Что сознательно не обещать первым релизом

Полная бухгалтерская оценка запасов; FIFO/средняя себестоимость; партии/серии/сроки годности; многовладельческое хранение; сложный WMS; производственные спецификации; распределенные транзакции между независимыми БД; произвольная рекурсивная схема; универсальная интеграция со всеми конфигурациями 1С; одинаковая семантика всех поисковых движков; налоговый движок для всех стран; маркетплейс с продавцами и расчетами.

Для них оставить понятные границы расширения, но не маскировать отсутствие реализации десятками nullable-полей и методов, которые ничего не гарантируют.
