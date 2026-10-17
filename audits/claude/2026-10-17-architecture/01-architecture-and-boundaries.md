# 01. Архитектура, границы пакетов, зависимости

Область: `packages/*/composer.json`, сервис-провайдеры, `tests/Arch`, `docs/architecture/packages.md`,
публичные пространства имён ядра. Идентификаторы находок (`ARC-xx`) сквозные с [findings.md](findings.md).

## 1. Что сделано хорошо

| Свойство | Как обеспечено | Оценка |
|---|---|---|
| Направление зависимостей | feature → core, никогда feature → feature, core → ничего (`ArchitectureGates::dependencies`) | эталонно; проверяется тестом с негативными фикстурами |
| Закрытость `Internal` | gate 2: нет импорта `…\Internal\…` через границу пакета и между модулями ядра | эталонно |
| Маркеры поверхности | `#[Api]` / `#[Spi]` на каждом публичном типе (gate 3) + `resources/contracts/php-api.json` и `composer api:check` | редкая для PHP-экосистемы дисциплина SemVer |
| Владение таблицами | `SchemaOwnership` + `TableNames` + gate 5 на миграции | хорошо, но см. ARC-04 |
| Нет Eloquent в публичных сигнатурах | gate 4; фактически Eloquent не используется вовсе | сильное решение (D12) |
| Встраиваемость | CRM ставит `core + sales`, магазин — `full`; дефолты портов в ядре (`singletonIf`) | цель достигнута |
| Порты между доменами | `Commerz\Ports\*` — единственный канал feature ↔ feature | правильный «hexagonal» каркас |

Итог: каркас **модульного монолита с портами** собран профессионально и защищён тестами. Ниже — то, что мешает
назвать его «идеально чистым».

## 2. Находки

### ARC-01 · High · Ядро — «толстое разделяемое ядро», знающее о feature-доменах

Декларация `packages.md`: *«The core knows nothing about feature packages: no class names, no contribution kinds
they declare.»* Формально (по именам классов) это так. Семантически — нет:

- `Quotes\Enums\QuoteAnchor` — **закрытый enum** с якорями `promotions.catalog`, `promotions.cart`, `delivery`,
  `promotions.delivery`, `tax` (`packages/core/src/Quotes/Enums/QuoteAnchor.php`). Новый домен, которому нужен
  свой якорь (сборы/fees, подарочные карты, бонусные баллы, депозит тары, страхование доставки, округление
  итога), требует релиза ядра.
- `Quotes\Data\Adjustment` несёт `promotionId` и `couponCode` (`Adjustment.php:31-32`) — понятия пакета promotions.
- `QuoteResult::appliedPromotions()` — свёртка для promotions внутри ядра (D25).
- `Ports\Promotions`, `Ports\Delivery`, `Ports\Stock`, `Ports\Prices`, `Ports\Customers` — ядро заранее описывает
  все известные домены. Порт для нового домена (лояльность, gift cards, B2B-кредит) = мажорное изменение ядра
  или «второе ядро».

Почему важно: семейство пакетов задумано расширяемым (giftcards, loyalty в «Future packages»), а сейчас каждый
такой пакет упрётся в ядро. Это нарушение OCP на уровне архитектуры, а не кода.

Рекомендация (целевая модель, см. [08-roadmap.md](08-roadmap.md) §3):

1. Якоря — **данные, а не enum**: ядро задаёт только фазы общего вида
   (`Pricing → Discounts → Shipping → Fees → Tax → Settlement`), пакеты регистрируют свои стадии с ограничениями
   порядка (`after: ['promotions.cart']`, `before: ['tax']`) — топологическая сортировка, как уже сделано для
   плагинов.
2. `Adjustment` получает нейтральный `origin: AdjustmentOrigin(kind: 'promotion', ref: 'promotion:…', attributes)`;
   `promotionId/couponCode` становятся частным случаем, а свёртка `appliedPromotions()` переезжает в promotions
   (порт `PromotionUsage::consume(QuoteResult $quote, …)` сам решает, что считать).
3. Порты нового домена объявляет **владелец домена** (как `declareKind`), а не ядро; ядро даёт механизм
   «participant» (см. EXT-04), а не конкретный порт.

### ARC-02 · High · Порты помечены только `#[Api]`, хотя их реализуют пакеты и хосты

`StockAvailability`, `StockReservations`, `StockReturns`, `PriceResolver`, `PromotionUsage`, `DeliveryOptions`,
`ShipmentBooking`, `Sellables`, `QuotePipeline` — `#[Api]`. Но `extension.md` прямо называет «Port implementation»
четвёртым механизмом расширения (внешний склад, CRM-провайдер). Для вызывающего добавление метода — minor, для
реализующего — **breaking**. Маркер `#[Spi(kind: Implement)]` ровно для этого и введён, но к портам не применён
(для сравнения: `CustomerDirectory` и `ContextResolver` помечены верно).

Рекомендация: пометить порты `#[Api]` + `#[Spi(Implement)]`; в `docs/reference/semver-policy.md` зафиксировать:
новый метод порта — только через новый интерфейс (`StockReservations2 extends StockReservations`) или абстрактный
базовый класс с дефолтом; `api:check` должен различать «Api-only» и «Implementable» поверхности и падать на
добавлении метода в implementable-интерфейс в minor-релизе.

### ARC-03 · High · Контракты портов привязаны к «одной БД и одной транзакции»

`StockReservations`, `StockReturns`, `PromotionUsage`: *«Every mutation joins the caller's database transaction on
the Commerz connection … and makes no network calls»* (`Ports/Stock/Contracts/StockReservations.php`). Это делает
D11 (одна локальная транзакция размещения) корректным, но **исключает удалённые реализации**: внешний WMS/ERP,
1С-остатки по API, маркетплейс-сток. При этом `extension.md` обещает «`StockAvailability` → external stock adapter».

Рекомендация — явно развести два протокола:

- `Local*` (текущий): участник транзакции, без сети;
- `Remote*`: двухфазный — `reserve` до транзакции с идемпотентным ключом `order:{id}` и TTL, `confirm` через outbox
  после коммита, `cancel` — компенсация (saga). Заказ получает состояние `stock_pending` → `confirmed`. Ядро
  предоставляет оркестратор; sales выбирает протокол по типу реализации порта.

Без этого «Port implementation» для склада де-факто ограничен «другой реализацией на той же PostgreSQL».

### ARC-04 · Medium · Владение таблицами: три источника правды и нет runtime/статического контроля записи

- Существительные объявлены трижды: `*ServiceProvider::NOUNS`, `tests/Arch/ownership.php`,
  `docs/architecture/packages.md`.
- Gate 5 проверяет **только миграции**. Правило CLAUDE.md «packages do not write to tables owned by another package»
  в коде соблюдается (проверено grep по `->table('…')`: каждый пакет обращается только к своим существительным), но
  ничем не гарантировано: `TableNames::table('orders')` из promotions вернёт имя без ошибки.

Рекомендация: единый источник — `composer.json → extra.commerz.nouns`; провайдер и gate читают его. Gate 7:
статический скан литералов `table('…')`/`$tables->table('…')` в `src/` — только свои существительные. Опционально
`TableNames::for(string $owner)` — именованный резолвер, который знает своего владельца.

### ARC-05 · Medium · Правило раскладки модулей нарушено в ядре и не проверяется

`packages.md`: *«Public categories of a module are only `Contracts`, `Data`, `Enums`, `Events`, `Exceptions`.»*
В ядре публичные классы лежат в корне модулей: `Commerz\PluginGraph`, `Plugins\{CommerzPlugins, PluginRegistrar,
PluginManifest, ContributionKind, PluginDescription, PluginSource}`, `Operations\Fingerprint`, весь `Money\*`,
`Measures\*`, `Identity\*`, `Schema\{TableNames, CommerzMigration, ConstraintNames}`, `Context\CommerceContext`.

Дополнительно экспортированы как SemVer-поверхность **валидационные помощники**, а не DTO:
`Stores\Data\Codes`, `Stores\Data\ChannelSettings`, `Catalog\Common\Data\CatalogCodes`, `PricingCodes`,
`PromotionCodes`. `Measures\Unit` одновременно `@internal` и `#[Api]` (`Unit.php:10`) — противоречие.

Рекомендация: либо (a) узаконить «kernel value objects в корне модуля» и добавить это в правило + gate, либо
(b) перенести; помощники валидации — в `Internal`, оставив публичными только сами команды. Gate 8: «в публичном
пространстве имён модуля — только разрешённые сегменты» (с явным allow-list для ядра).

### ARC-06 · Medium · Дублированная инфраструктура в шести пакетах

- `Common/Internal/Clock.php` — **6 идентичных копий** (`Clock::now(): string`).
- `Common/Internal/Rows.php` — 6 копий по 80–103 строки, различаются набором методов.
- Однотипные `*Config::fromArray` с ручной валидацией.

Причина — запрет импорта чужого `Internal` (правильный) при отсутствии **публичной инфраструктуры ядра**.
Рекомендация: в ядре `Commerz\Support` (`#[Api]`, стабильно): `Clock` (PSR-20), `RowReader`, `ConfigReader`
(типизированный ридер с путём ключа). Это уменьшит ~600 строк и даст единую точку для фиксации времени в тестах
(см. KRN-05).

### ARC-07 · Medium · Service locator как обход проблемы компиляции реестра

- `CommerzPlugins::builtIn()` берёт `Container::getInstance()` (`Plugins/CommerzPlugins.php:23`) вместо `$this->app`
  провайдера — глобальное состояние, ломается при нескольких экземплярах приложения (Testbench parallel,
  Octane-воркеры с пересозданием контейнера).
- `BaseStage`, `TaxStage`, `VariantSellables`, `PromotionSessions`, `Sales` — достают зависимости из контейнера в
  рантайме. Комментарий в `VariantSellables.php:79`: *«Resolved here, not injected: the registry instantiates its
  providers while it is being compiled.»*

Корень — EXT-01 (жадное создание вкладов при компиляции). После перехода на ленивые фабрики вкладов нормальный DI
снова работает, а «пересвязать порт в тесте» решается `app()->instance()` до первого обращения или фейком
в testkit.

### ARC-08 · Low · `Sales` как ленивый локатор модулей

`Sales::carts()` и т.д. — `$this->container->make(...)` (`Sales.php:28-58`). Мотивация верная (CRM без склада не
должен требовать порт склада), но корневой класс получает скрытые зависимости, а модули — повторное разрешение.
Альтернатива: инъекция `Closure(): Carts` фабрик или Laravel `DeferrableProvider` для модулей с тяжёлыми портами.

### ARC-09 · Low · Нет «read-model» для хостов на Eloquent

Отказ от Eloquent внутри — сильное решение. Но хост на Laravel ожидает `$user->orders()`, Nova/Filament-ресурсы,
Scout. Сейчас единственный путь — `Orders::list()` с курсором. Идея: отдельный пакет `commerz-eloquent`
с **read-only** моделями поверх тех же таблиц (запрет `save()` на уровне модели, `#[Api]` как «read model»),
чтобы админки строились без нарушения single-writer (D12 это не ломает: запись остаётся через команды).

## 3. Карта зависимостей (фактическая)

```text
                ┌─────────────────────── axiomasoft/commerz (core) ───────────────────────┐
                │ Money Measures Identity Context Errors Configuration Schema Operations    │
                │ Outbox Plugins Stores Pagination Quotes Ports{Sellables,Stock,Prices,     │
                │ Promotions,Delivery,Customers}                                            │
                └──────▲──────────▲─────────▲──────────▲───────────▲──────────▲──────────────┘
                       │          │         │          │           │          │
                   catalog   inventory   pricing  promotions   shipping     sales ──(ports only)──▶ все
                   (variant   (Stock*)   (Price-   (Promotion  (Delivery*,  (consumer: Sellables,
                    provider)            Resolver,  Usage, 3    delivery     Stock*, PromotionUsage,
                                         tax stage) stages)     stage)       Delivery*, Customers)
```

Реальная связность через ядро — «звезда», что правильно. Связность **по смыслу** (sales ↔ promotions через
`appliedPromotions`, shipping ↔ sales через `DeliverySelection`/`Parcel`, pricing ↔ catalog через свободную
строку `tax_class`) — выше, чем видно по графу Composer; это нормально для коммерции, но такие соглашения стоит
типизировать (например, `TaxClassRef`), чтобы опечатка в `tax_class` варианта не превращалась молча в
`default_class` или `TaxRateNotFound`.

## 4. Оценка по оси

| Критерий | Балл (из 10) | Комментарий |
|---|---|---|
| Направление зависимостей | 10 | защищено тестами |
| Независимость установки пакетов | 8 | CI не ставит пакеты изолированно (QA-01) |
| Чистота ядра (OCP) | 5 | ARC-01, ARC-03 |
| Явность SemVer-поверхности | 8 | маркеры + манифесты; минус ARC-02, ARC-05 |
| Отсутствие дублирования | 6 | ARC-06 |
| DI-гигиена | 6 | ARC-07 |
