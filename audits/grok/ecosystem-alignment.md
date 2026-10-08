# Экосистема: что Commerz обязан унаследовать

Источники прочитаны напрямую (shallow clone / локальная копия), ревизии:
`corex@8d9dc86c` (ветка `improve/core-hardening-1`, доктрина совпадает с `main` по затронутым файлам),
`chatom@8d6b2ac`, `vaulter@b5cc56d`, `azguard-private@50eb32a`. Пути ниже — от корня соответствующего репозитория.

Главное отличие от [ecosystem-conventions.md](../../docs/discovery/2026-10-08/ecosystem-conventions.md):
тот отчёт **не изучал CoreX** («Corex/Agentools не исследовались», §1). А именно в CoreX записана самая
свежая и строгая доктрина экосистемы: раскладка модулей, словарь суффиксов, схема префиксов, UUIDv7,
запрет межпакетных FK, PostgreSQL-first. Commerz должен равняться на неё, а Chatom/Vaulter/AzGuard
использовать как подтверждение и источник готовых механизмов.

## 1. Где живёт Commerz: standalone, не `corex/*`

| Факт | Свидетельство |
|---|---|
| Тест агностичности CoreX: «`corex/*` полезен проекту БЕЗ CRM/CMS/**Store**». Не прошёл — уровень 1 или standalone | `corex/docs/dev-doctrine.md:17` |
| Cross-ecosystem пакеты — standalone | `corex/docs/dev-doctrine.md:16,186` |
| Chatom, Vaulter, AzGuard не зависят от CoreX (в `packages/*/composer.json` нет `axiomasoft/corex`) | проверено grep по трём репозиториям |
| CoreX сам интегрирует AzGuard через мост `corex-access-azguard`, а не наоборот | `corex/docs/architecture/table-ownership.md` (раздел AzGuard) |

**Решение.** `commerz` — standalone-монорепо как Chatom: `packages/<name>/`, Composer `axiomasoft/commerz*`,
namespace `Commerz\`. Ядро Commerz не требует CoreX. Интеграция — мост `axiomasoft/commerz-corex`
(manifest модуля `type: corex-module`, `SchemaSet`, tenancy-контекст, outbox `sys_outbox_messages`, адаптер
`corex-custom-fields`, валюты `corex-intl`). Так Commerz можно поставить в Flex-продукт и в чужое Laravel-приложение.

**Принцип CoreX переносится внутрь Commerz:** «ядро — единственная точка взаимодействия». У Commerz есть
своё ядро `axiomasoft/commerz` (контракты, value objects, реестр плагинов), модули зависят только от него и
общаются через его контракты (подробно — [architecture.md](architecture.md)).

## 2. Стек

| Решение | Основание |
|---|---|
| PHP `^8.4`, Laravel `^13` | CoreX: «все пакеты — PHP 8.4–8.5 × Laravel 13» (`corex/docs/dev-doctrine.md:176`). Chatom/Vaulter держат `^12\|^13` только из-за истории; новому пакету 12 не нужен |
| **Только PostgreSQL** (≥16, CI на 17) | `corex-custom-fields` — PG-only (`corex/packages/custom-fields/README.md:3`); CoreX гоняет обязательный `test:pg` (`dev-doctrine.md` §6). Commerz опирается на `EXCLUDE USING gist`, частичные уникальные индексы, `jsonb`, `CHECK` — проверено в [prototype](prototype/results.md). Vaulter честно пишет, что SQLite/MariaDB дают другие гарантии (`vaulter/docs/guide/database-compatibility.md`) |
| `brick/math` — единственная арифметическая зависимость | Уже в экосистеме: `corex/packages/intl/composer.json:11–12` (`brick/math`, `brick/money`) |
| Pest, PHPStan max + strict-rules, Pint, Rector | Общий набор всех четырёх репозиториев (корневые `composer.json`) |

## 3. Раскладка кода: модуль → публичные категории → `Internal`

CoreX закрепил это нормой и гейтом; у Chatom то же, но с каталогом `Models` в модуле.

| Норма | Свидетельство |
|---|---|
| Публичные категории модуля — только `Contracts`, `Data`, `Enums`, `Events`, `Exceptions`; слоевые каталоги (`Support`, `Models`, `Commands`) запрещены | `corex/docs/architecture/constitution.md:158` (STR-006) |
| `Internal/` ровно под модулем, закрыт для других модулей и пакетов | `constitution.md` STR-005; `structure-profiles.md` |
| Один use case — один feature module | `constitution.md:130` (STR-002) |
| Публичная поверхность — атрибут `#[Api]` / `#[Spi(Implement\|Call)]` на каждом типе | `constitution.md:168` (API-001); Chatom: `packages/core/src/Messages/Data/SendMessage.php:15`, `Plugins/Contracts/Plugin.php:15` |
| Модуль Chatom: `Messages/{Contracts,Data,Enums,Events,Exceptions,Http,Internal,Models,Pipeline}` | `chatom/packages/core/src/Messages/` |

**Для Commerz** (пример модуля склада):

```text
packages/inventory/src/
  InventoryServiceProvider.php
  Reservations/               ← feature module
    Contracts/  StockReservations.php           #[Spi(Call)]
    Data/       ReserveStock.php, ReservationResult.php   (final readonly)
    Enums/      ReservationState.php
    Events/     StockReserved.php
    Exceptions/ InsufficientStock.php
    Internal/   ReserveStockHandler.php, EloquentStockLevel.php, StockLevelRepository.php
  Movements/ …
  Levels/ …
```

Правило пользователя «чёткое размещение: сервисы, репозитории, enum, DTO» ложится так: DTO → `Data/`,
enum → `Enums/`, «сервисы» → `Internal/*Handler` (одна операция), репозитории → `Internal/*Repository`,
Eloquent-модели — `Internal/` (это деталь хранения, не API). Chatom держит `Models/` публичными и разрешает их
подмену через config (`chatom/packages/core/config/chatom.php:53`); для Commerz подмену моделей **не берём**
(обоснование — [flexibility-and-config.md](flexibility-and-config.md) §5).

## 4. Имена классов

Словарь суффиксов CoreX (`corex/docs/architecture/naming.md:23–38`) применяем без изменений:
`Repository` — доступ к данным одной сущности (`:27`), `Resolver` — выбор значения/реализации (`:29`),
`Registry` — детерминированный набор вкладов (`:30`), `Handler` — ровно одна операция (`:35`),
`Policy`, `Codec`, `Compiler`, `Sweeper`. **`Service` и `Manager` не вводятся** (`:50–51`).
Идентификатор в сигнатуре называет владельца (`$orderId`, не `$id`), суммы — с единицей: `$amountMinor` (`:59–67`).
Enum-случай — значение: `ReservationState::Active` (`:68`).

## 5. Таблицы: префиксы, имена ограничений, владение

### 5.1. Что есть в экосистеме

| Пакет | Префикс | Механизм | Свидетельство |
|---|---|---|---|
| CoreX | 3–4 буквы **на пакет**: `mod_`, `tsk_`, `med_`, `ntf_`, `wsp_`, `tnt_`, `aut_`, `crm_`, `root_`; общий `sys_` (разделённый, не владелец) | литералы в миграциях + каталог владения + `schema-gate` S9 | `corex/docs/architecture/table-ownership.md:26` (раздел `sys_`), строки `mod_modules`, `tsk_tasks`, `med_files`, `crm_deals` |
| AzGuard | `azg_` | именованные storages: connection + prefix + host key mode | `azguard-private/packages/core/config/azguard.php:144`; `src/Storage/Storage.php:56` |
| Vaulter | `v_` | `DatabaseConfig::table()` | `vaulter/packages/core/config/vaulter.php:37`; `src/Configuration/DatabaseConfig.php:17–27` |
| Chatom | `chatom_` | `SchemaSpace::table()` = map ?? `{prefix}_{logical}`, fingerprint схемы | `chatom/packages/core/src/Schema/Data/SchemaSpace.php:34–37` |

Имена ограничений в CoreX единообразны: `{table}_{columns}_{uq|ix|fk|ck}` и `{table}_pk` (весь каталог `table-ownership.md`;
Laravel на PostgreSQL игнорирует имя PK, поэтому CoreX переименовывает `_pkey` → `_pk`: `corex/packages/tasks/database/migrations/tenant/2026_10_01_160020_create_tsk_stages.php:16,31`),
длинные — с hash-суффиксом (`med_conversions_file_id_profile_key_profile_digest__67e95e41_uq`). Chatom сокращает до
63 байт детерминированно (`chatom/packages/core/src/Schema/Internal/Services/SchemaNames.php:30`).

### 5.2. Предложение: `cmz_` + существительное модуля

```text
cmz_<module-noun>_<entity>     cmz_stock_levels, cmz_promotion_usages, cmz_coupon_redemptions
```

- **Один префикс пакета `cmz_`**, по умолчанию, настраивается **до первой установки** и фиксируется в
  `cmz_schema_state` (fingerprint как у Chatom). Смена после установки — ошибка загрузки, не тихое переименование.
- **Владение — первым словом логического имени**, закреплённым за модулем (закрытый список, проверяется архитектурным тестом):

| Модуль / пакет | Зарезервированные существительные | Примеры |
|---|---|---|
| core `axiomasoft/commerz` | `store`, `channel`, `schema`, `outbox` | `cmz_stores`, `cmz_channels` |
| `commerz-catalog` | `product`, `variant`, `option`, `attribute`, `category` | `cmz_products`, `cmz_variant_option_values` |
| `commerz-inventory` | `stock` | `cmz_stock_levels`, `cmz_stock_reservations` |
| `commerz-pricing` | `price`, `tax` | `cmz_prices`, `cmz_tax_rates` |
| `commerz-promotions` | `promotion` | `cmz_promotions`, `cmz_promotion_usages` |
| `commerz-coupons` (плагин) | `coupon` | `cmz_coupons`, `cmz_coupon_redemptions` |
| `commerz-sales` | `cart`, `order`, `payment`, `shipment`, `return`, `refund` | `cmz_orders`, `cmz_payment_transactions` |
| сторонний плагин | `x_<vendor>` | `cmz_x_acme_loyalty_points` |

- Ограничения и индексы — по канону CoreX: `cmz_stock_levels_stock_item_id_location_id_uq`, `…_ck`, `…_fk`;
  > 63 байт — детерминированный hash-суффикс (алгоритм Chatom).

**Почему не префикс на модуль, как в CoreX (`cat_`, `inv_`, `ord_`).** CoreX владеет своей БД целиком; Commerz
встраивается в чужие приложения, где `cat_`, `ord_`, `prc_` легко заняты, а 6–7 префиксов одного продукта засоряют
схему хоста. Один `cmz_` — 4 символа, как `azg_`; модульное существительное сохраняет читаемость и границу владения
не хуже трёхбуквенного кода. **Почему не `commerz_`** — длинно (Chatom-вариант `chatom_` — единственный длинный в экосистеме).
Почему не `cm_` (из design-hypotheses) — двухбуквенные коды слишком конфликтны и в экосистеме не используются.

### 5.3. Ключи

| Решение | Основание |
|---|---|
| PK сущностей — **UUIDv7**, `uuid` PostgreSQL, генерация в приложении | CoreX ADR-003 (`corex/docs/dev-doctrine.md:175`); Chatom default `uuid7` (`chatom/packages/core/src/Schema/Enums/IdStrategy.php:16`); `corex-custom-fields` требует v7 для новых id (`src/Data/AttributeDefinition.php:50`) |
| Журналы, на которые никто не ссылается извне (`cmz_stock_movements`, `cmz_order_events`, `cmz_outbox_messages`), — `bigint GENERATED ALWAYS AS IDENTITY` | компактнее, монотонно, удобно для курсоров |
| Ключ стратегии **не настраивается** (в отличие от `IdStrategy` Chatom и `strategy` Vaulter `config/vaulter.php:40`) | три стратегии = три DDL и тройная матрица тестов; Commerz-данные импортируются/сливаются между БД (DB-per-account CoreX ADR-004) — нужен глобально уникальный ключ |
| Ссылки на хост (покупатель, оператор) — `*_ref varchar(128)` вида `type:id`, без FK | Vaulter не создаёт FK на host-таблицы (`vaulter/packages/core/src/Schema/VaulterSchema.php:62`), AzGuard разделяет host key mode; CoreX DATA-004 запрещает межпакетный FK (`constitution.md:263`) |
| Межпакетных FK внутри Commerz нет: `cmz_stock_item_links.purchasable_id` не ссылается на `cmz_variants` | DATA-004; inventory ставится без catalog |

## 6. Конфигурация

- Один файл на пакет `config/commerz-<pkg>.php` + `final readonly` типизированный аксессор (`CommerzConfig`),
  как `Vaulter\Configuration\DatabaseConfig` (`vaulter/packages/core/src/Configuration/DatabaseConfig.php:13–27`).
  Коды значений — backed enum, неизвестное значение → исключение при загрузке.
- Только FQCN и скаляры: переживает `config:cache` (Chatom явно запрещает closures — `chatom/packages/core/config/chatom.php`, раздел `spaces`).
- Плагины регистрируются кодом (manifest), а не строками в БД: CoreX запрещает «подписки в БД» (`dev-doctrine.md:78`).

## 7. Ошибки

Backed enum кодов + базовое исключение пакета с машинным кодом и категорией, как у Chatom
(`chatom/packages/core/src/Errors/Enums/ErrorCode.php`, `Errors/Exceptions/ChatomException.php`) и CoreX
(«Error constructors require `ErrorCode`», `corex/packages/custom-fields/README.md`, раздел Typed errors).
`Commerz\Errors\Enums\CommerzErrorCode::InsufficientStock` → `commerz.inventory.insufficient_stock`.

## 8. Fail-closed вместо тихой деградации

Свежий урок из CoreX: класс скоупа изоляции задавался строкой в конфиге и при опечатке **молча отключался**
(исправлено в `corex@improve/core-hardening-1`, контракт `WorkspaceScopeProvider`). `corex-custom-fields` устроен
правильно: без зависимостей он отвечает `UnavailableAttributes` — «observable fail-closed state, not a silent degradation»
(`corex/packages/custom-fields/README.md:14–16`).
**Правило для Commerz:** неизвестный тип условия акции, атрибута, гейтвея, стратегии в БД или конфиге → явная ошибка
загрузки / акция в состоянии `unsupported`, но никогда не «пропустить и посчитать без неё».

## 9. Что переиспользовать из CoreX (через мост, не в ядре)

| Компонент CoreX | Что даёт Commerz | Почему не жёсткая зависимость |
|---|---|---|
| `corex-custom-fields` | JSONB/EAV-хранилище 15 скалярных типов, управляемые индексы (`README.md:5–9`; типы — `src/Internal/Values/FieldValueValidator.php:189–205`) | Ключ значения — `(entity, record_id)` строками без FK, нет локализованных значений, словарей опций, осей вариантов, семейств; требует CoreX. **Годится для доп. полей заказов/покупателей**, не для каталога |
| `corex-intl` | метаданные валют `CurrencyInfo(isoCode, exponent)` (`src/Currency/Data/CurrencyInfo.php:10–19`), курсы | ядру Commerz нужен только exponent валюты — встроенная таблица ISO 4217 + порт `CurrencyMetadata` |
| `corex` Messaging | outbox/inbox `sys_outbox_messages` | standalone-установке нужен свой `cmz_outbox_messages` |
| `corex-tenancy` | DB-per-account, резолвер соединения | Commerz сам tenancy не реализует (см. architecture.md §6) |
| `corex-media` / Vaulter | файлы товаров | порт `ProductMediaStore`; мосты `commerz-corex` / `commerz-vaulter` |
| `corex-search` | индекс каталога | порт `CatalogIndexer`; SQL-реализация по умолчанию |

**Типы атрибутов Commerz называем так же, как в `corex-custom-fields`** (`text`, `int`, `decimal`, `bool`, `date`,
`datetime`, `select`, `multiselect`, `reference`, `money` = `{minor:int, currency}`, `url`…), чтобы мост был
тождественным отображением, а не переводчиком.

## 10. Расхождения внутри экосистемы, которые Commerz не должен копировать

| Расхождение | Как поступает Commerz |
|---|---|
| Префиксы: `v_` / `azg_` / `chatom_` / 3-буквенные CoreX / разделённый `sys_` | один `cmz_` + существительное модуля (§5.2) |
| ID: ULID (Vaulter) / UUIDv7 (Chatom, CoreX) / bigint (AzGuard grant rows) | UUIDv7 без настройки |
| Laravel `^11` (AzGuard) / `^12\|^13` / `^13` (CoreX) | `^13` |
| `Models/` публичный и подменяемый (Chatom) vs только в `Internal` (CoreX) | CoreX-вариант |
| Горизонтальные `Actions/Repositories/Data` (Vaulter) vs feature modules (CoreX, Chatom) | feature modules |
| `corex/docs/architecture/table-ownership.md` называет таблицы AzGuard `az_guard_*`, а сам AzGuard по умолчанию `azg_` (`config/azguard.php:144`) | (не наш дефект; отмечено как замеченный дрейф) |
