# 07. Качество, инструменты, CI, документация, DX

Находки `QA-xx` (качество/CI), `DOC-xx` (документация), `DX-xx` (опыт разработчика-хоста).

## 1. Результаты проверок (запущены в рамках аудита, 2026-10-17)

| Проверка | Команда | Результат |
|---|---|---|
| Стиль | `scripts/php84 composer lint:check` | ✓ pint passed |
| Статический анализ | `scripts/php84 composer analyse` (PHPStan max + strict + Larastan) | ✓ 0 ошибок, 929 файлов |
| Rector | `scripts/php84 composer refactor:check` | ✓ без изменений |
| Архитектурные гейты | `scripts/php84 composer test:arch` | ✓ 17 тестов, 54 утверждения |
| Манифесты API | `scripts/php84 composer api:check` | ✓ 4 теста, 28 утверждений, манифесты синхронны |
| Полный набор | `scripts/php84 vendor/bin/pest --compact` (PostgreSQL 17, `docker compose`) | ✓ **2106 тестов, 14 759 утверждений**, 290 с |

Уровень гигиены — высокий: PHPStan max без baseline, нулевой долг Rector, ~1 470 тестовых сценариев
(`it`/`test`; 2106 с датасетами) на ~51,5 тыс. строк `src`, конкурентные E2E.

## 2. Находки QA

### QA-01 · Medium · CI не доказывает независимую установку пакетов

- Все тесты идут в монорепо с общим автозагрузчиком (`composer.json` → `autoload-dev` для всех пакетов).
  Gate зависимостей проверяет `composer.json` и импорты, но **рантайм-неявная** зависимость (например,
  строковый ключ `tax_class`, ожидание чужой миграции, ресолв класса по имени из конфига) не будет поймана.
- `scripts/test-consumer-install.sh` (D36: установка архивов 0.1.0 в чистое Laravel-приложение, сценарии
  full и CRM) существует, но **не вызывается в CI** (`.github/workflows/ci.yml`).
- Матрица: PHP 8.4 × Laravel 13 × PostgreSQL 17. D2 обещает «PostgreSQL ≥ 16» — 16 не тестируется
  (`NULLS NOT DISTINCT` с 15, `btree_gist`-поведение, планировщик).
- Нет `--prefer-lowest` (нижние границы `brick/math ^0.14.2`, `illuminate/* ^13.0` не проверяются).
- Нет `composer validate --strict` по каждому пакету и `composer audit`.

Рекомендация: job «isolated packages» — матрица по пакетам: копия пакета → `composer install` с path-репо
только для ядра → `pest packages/<pkg>/tests`; job «consumer» — `test-consumer-install.sh`; PostgreSQL 16 и 17;
`prefer-lowest`; `composer audit`.

### QA-02 · Medium · Тесты пакетов вне PHPStan

`phpstan.neon` анализирует `packages/*/src` и только `packages/testkit/tests`. Тесты остальных пакетов
(~285 файлов) не проходят статический анализ — опечатки в именованных аргументах тестов, устаревшие сигнатуры
ловятся только запуском. Rector тесты покрывает, PHPStan — нет. Добавить `packages/*/tests` хотя бы с level 6.

### QA-03 · Medium · Нулевая наблюдаемость

В `packages/*/src` нет ни одного `LoggerInterface`/`Log::`. Нет событий для метрик (длительность котировки,
отказы `QuoteFailed` по стадиям, лаг outbox, конфликты idempotency, таймауты блокировок `55P03`). Для движка,
который «правилен под нагрузкой», это главный эксплуатационный пробел: оператор не увидит, что 5 % размещений
падают по `lock_timeout`.

Рекомендация: в ядре `Commerz\Observability` — тонкий порт `Telemetry` (span/counter/histogram) с no-op
дефолтом и адаптером OpenTelemetry в отдельном пакете; PSR-3 логгер в relay, журналах платежей/бронирований
(unknown outcome — всегда warning с reference).

### QA-04 · Low · Нет мутационного тестирования критичной арифметики

`Money`, `MoneyAllocator`, `QuoteState::allocatedOrderAdjustments`, `TotalsStage`, `PriceSelection`,
`DiscountGuard` — идеальные кандидаты для Infection с порогом MSI (быстрые чистые юнит-тесты).

### QA-05 · Low · Миграции не публикуются

Все 7 провайдеров используют только `loadMigrationsFrom` (нет `publishesMigrations`). Хост не может
опубликовать миграции, чтобы встроить их в свой порядок (например, добавить FK из своей таблицы `cmz_x_…` или
выполнить их в другом соединении/схеме по своему сценарию). Правило `.claude/rules/package-migrations.md`
прямо называет оба варианта; рекомендуется дать выбор: `publishesMigrations(..., 'commerz-migrations')` +
`Commerz::ignoreMigrations()`.

### QA-06 · Low · Размер репозитория и артефакты агентов

В репозитории ~1 900 файлов, из них значительная часть — исследовательский корпус (`docs/discovery`, 
`audits/*`, `plans/archive/**/review/*.ndjson.gz`). Для split-зеркал пакетов это не критично
(`.gitattributes export-ignore`), но монорепо тяжелеет. Рекомендация: вынести корпус в отдельный репозиторий
или в Brain-хранилище, оставив ссылки.

## 3. Документация

Сильные стороны: VitePress-сайт, архитектура как «связывающие документы», генерируемый API-reference из
манифестов (`docs:reference --check` в CI), how-to для плагинов/шлюзов/перевозчиков, реестр инвариантов,
журнал решений.

### DOC-01 · Medium · Дрейф архитектурных документов от кода

| Где | Утверждение | Факт |
|---|---|---|
| `docs/architecture/README.md:64` | «accepted architecture decisions D1–D15» | в `decisions.md` D1–D35 |
| `docs/architecture/api.md:66` | ссылка на D38 | D36–D38 есть в `plans/archive/…/decisions/`, но **нет** в `decisions.md` |
| `docs/architecture/extension.md:77-79` | правило декодирует конфиг через `configVersion()`, `decodeConfig(array, int)` | правила: `version()`, `normalize(RuleParams, RuleScope)`, `upgrade(RuleParams, int)`; `configVersion()` — у `AttributeType` |
| `docs/architecture/packages.md:97` | раскладка `Internal/ReserveStockHandler.php, ReservationRepository.php, Models/Reservation.php` | реальные классы: `Database*`, `*Rows`, `*Journal`, Eloquent-моделей нет вовсе |
| `docs/architecture/data.md:49-50` | весовые товары «with a sale step» | шага продажи нет в коде (KRN-03) |
| `docs/architecture/extension.md` / `config/commerz-inventory.php` | стратегия `acme.nearest` | невыразима (EXT-07) |
| `docs/architecture/packages.md` | «Public categories of a module are only Contracts, Data, Enums, Events, Exceptions» | нарушено в ядре (ARC-05) |
| `docs/architecture/extension.md` таблица kinds | `outbox_consumer` — «—» | корректно, но outbox пишет только sales (KRN-09) |

Рекомендация: «doc-tests» — скрипт, проверяющий, что все `Dnn` из документов существуют в `decisions.md`, что
все упомянутые FQCN/методы существуют (через `php-api.json`), и что README-счётчики решений совпадают.
Добавить в CI рядом с `docs:reference --check`.

### DOC-02 · Low · Решения в двух местах

Журнал решений существует в `docs/architecture/decisions.md` (кратко) и в `plans/archive/**/decisions/D*.json`
(полно). После архивации плана документ должен быть единственным источником; D36–D38 нужно перенести.

## 4. DX хоста

### DX-01 · Medium · Нет «рецептов» установки

`commerz:install` публикует только конфиг ядра. Пресеты `shop|crm|pim` с согласованными значениями
(PKG «full»), `commerz:doctor` по всем пакетам — сократят время до первой продажи.

### DX-02 · Medium · Нет транспортного слоя — даже эталонного

Сознательно (`commerz-http` в будущем). Но без него у хоста нет эталона маппинга исключений на HTTP
(`category()` → статус), сериализации `Money`/`LocalizedText`/`Page`, курсоров. Минимум: `CommerzException::
toProblem(): array` (RFC 9457) в ядре и JSON-сериализация value objects — это не транспорт, а wire-формат.

### DX-03 · Info · Фасады

Фасады несут `@method static` (проверено на `Sales`) — автодополнение работает. Стоит генерировать эти
docblock'и из `php-api.json`, чтобы новые accessor'ы не забывались.

### DX-04 · Low · Именованные аргументы как основной стиль — хорошо, но конструкторы DTO длинные

`CreatePromotion` — 16 параметров. Для команд с >8 параметрами стоит дать fluent-builder
(`CreatePromotion::draft('autumn-10')->cart()->coupon()->percentOff(1000)->build()`), не ломая конструктор.
