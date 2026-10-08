# Commerz — независимое исследование (Grok)

Папка `audits/grok/` устроена как `audits/chatgpt/`: только мои материалы, чужие файлы не менялись. Код в
`code-examples/` и SQL в `prototype/` **проверены запуском**, это не просто иллюстрации.

## Позиция в пяти строках

1. Commerz — **самостоятельное семейство пакетов** экосистемы (как Chatom/Vaulter/AzGuard), не зависит от CoreX, но
   копирует его дисциплину: раскладку модулей, словарь суффиксов, `#[Api]/#[Spi]`, fail-closed, DATA-004.
2. **Ядро-контракты + 6 доменных пакетов**, которые зависят только от ядра; **купоны — плагин** к пакету акций.
3. **Только PostgreSQL**, UUIDv7, один префикс **`cmz_`** + существительное модуля, деньги в `*_minor bigint`,
   количества целыми, enum как `varchar + CHECK`, без soft delete и без `tenant_id`.
4. Склад: **счётчики + журнал + адресные брони**, резерв одним условным UPDATE; акции: **чистые функции**,
   стадии и детерминированный stacking; свойства: **колонки + реляционные опции + JSONB-документ + синхронная проекция**.
5. Гибкость данных максимальная, конфиг — минимальный и типизированный, поведение — через закрытый набор SPI;
   без подмены моделей.

## Документы

| Файл | О чём |
|---|---|
| [ecosystem-alignment.md](ecosystem-alignment.md) | что унаследовать от CoreX/Chatom/Vaulter/AzGuard (с file:line), префиксы, ключи, ошибки |
| [architecture.md](architecture.md) | пакеты и зависимости, ядро, контракты, конвейер цены, транзакция заказа, состояния, события |
| [database.md](database.md) | правила схемы, **полный список таблиц** по пакетам, ER-диаграммы, склад, деньги, снимок, индексы |
| [promotions-and-coupons.md](promotions-and-coupons.md) | модель акции, SPI, алгоритм, распределение, лимиты/бюджеты, купоны-плагин |
| [product-properties.md](product-properties.md) | поля / опции / атрибуты / кастомизации, типы товаров, тип атрибута как плагин, проекция фасетов |
| [flexibility-and-config.md](flexibility-and-config.md) | что данными, что конфигом, что кодом; плагины, конвейеры, расширение данных |
| [open-source-comparison.md](open-source-comparison.md) | Sylius, Lunar, Vanilo, Aimeos, Medusa, Saleor, Solidus, Magento, Shopware, Bagisto, Akeneo, Shopify, commercetools — со ссылками на код |
| [risks-and-open-questions.md](risks-and-open-questions.md) | 12 пунктов «Нужно обсудить», технические риски, **таблица расхождений** с другими документами |
| [code-examples/](code-examples/README.md) | PHP 8.4: Money, конфиг, плагины, конвейер, движок акций, купоны-плагин, резерв, тип атрибута, миграции |
| [prototype/](prototype/results.md) | эксперименты E1–E8 на PostgreSQL 17 (pgbench) |

## Ключевые решения

| # | Решение | Обоснование кратко |
|---|---|---|
| 1 | Пакеты: `commerz` (ядро) · `-catalog` · `-inventory` · `-pricing` (+налоги) · `-promotions` · `-sales`; `-coupons` — плагин | у каждого есть установка без соседа; `stores`/`support` — ядро, `search`/`integrations` — порты |
| 2 | Префикс `cmz_` (настраиваемый, заморожен в `cmz_schema_state`), владение — существительным: `cmz_stock_*`, `cmz_promotion*`, `cmz_coupon*`; сторонние плагины — `cmz_x_<vendor>_*` | коротко, читаемо, проверяемо тестом; 7 модульных префиксов не дают выигрыша |
| 3 | UUIDv7 из приложения; журналы — `bigint identity`; ссылки на хост — `*_ref varchar(128)` без FK; FK — только вниз по зависимости | экосистема (CoreX ADR-003, Chatom); ID до INSERT; модули без скрытых связей |
| 4 | Деньги `*_minor bigint` + `currency char(3)`; ставки `rate_bp`; количества — целые в базовой единице | ни одного float; совместимо с `money` в corex-custom-fields |
| 5 | Склад: `cmz_stock_levels` (on_hand/reserved/safety_stock) + `cmz_stock_movements` (append-only, balance_after) + `cmz_stock_reservations` | 640/640 конкурентных резервов без oversell (E2); наивный read-then-write терял 620 обновлений (E3) |
| 6 | Резерв при размещении заказа, списание при отгрузке; ленивое истечение в той же транзакции; идемпотентность — естественные ключи | нет корзин-«зомби», нет `command_receipts`; проверено 8 процессами |
| 7 | Акции: стадии catalog → cart → shipping; `stacking` = stackable / exclusive / stop_further; priority DESC, code ASC; условия/эффекты — чистые функции с typed config + `config_version`; отказы объясняются | повторяемый расчёт, превью без побочных эффектов; совпадает с commercetools/Solidus/Shopify |
| 8 | Лимиты и бюджеты расходуются в транзакции заказа (coupon → promotion → customer, `lock_timeout 3s`), возвращаются при отмене; без claim-фазы | неоплаченный заказ и есть удержание; лимит 10 при 40 конкурентных попытках → ровно 10 |
| 9 | Купоны — плагин-**активатор**: корзина хранит общие «введённые коды», ядро акций не знает слова «купон» | реферальные/ссылочные коды подключаются без изменения sales/promotions |
| 10 | Свойства: фиксированные колонки; опции/варианты реляционно (составные FK, UNIQUE `option_signature`); атрибуты — JSONB-документ + синхронная `cmz_product_attribute_index`; тип товара задаёт уровень атрибута (товар **или** вариант) | карточка за 1 строку, фасеты по одному варианту (E8), без EAV-JOIN и fallback-магии |
| 11 | Плагины: manifest → компиляция → замороженный реестр; ключи вместо FQCN; неизвестный плагин → `unsupported` (fail-closed); без подмены моделей | предсказуемость, диагностика `commerz:plugins`, урок CoreX/AzGuard |
| 12 | Арендаторы — соединением (DB-per-tenant), без `tenant_id`; несколько магазинов и каналов в одной БД — штатно | CoreX ADR-004; shared-tenancy — решение до первой миграции |

## Главные расхождения с существующими материалами

Подробно с причинами — [risks-and-open-questions.md §3](risks-and-open-questions.md#3-расхождения-с-другими-документами).

- Против `scope_id` в каждой таблице, `stock_resources/dimension_key`, `command_receipts`, `coupon_claims`/quota-таблиц,
  `condition_tree`, fallback-цепочек и значений атрибутов по каналу (discovery: design-hypotheses, logical-schema-candidate).
- Против typed EAV с атомами/группами, `BIGINT + public_id` и 12 пакетов (ChatGPT architecture-appendix).
- Против асинхронной проекции атрибутов (вариант G) — фасеты не должны врать после сохранения.
- Отчёт об экосистеме не учёл CoreX — а он главный источник конвенций (раскладка, суффиксы, DATA-004, ADR-003/004).
- Согласны: `cmz_`, оплата ≠ физика, без глобального оптимума скидок, PostgreSQL-first, снимок заказа.

## Открытые вопросы владельцу (кратко)

SaaS на общих таблицах? · Commerz без CoreX — реальный сценарий? · только Laravel 13/PG? · весовые товары ·
один префикс · момент резерва · сложение скидок от сниженной цены · атрибуты по каналу · подарочные сертификаты
отдельно от акций · цены с НДС · расход лимитов неоплаченными заказами · 6 пакетов или 1 с модулями.

## Как проверить

```bash
cd audits/grok/code-examples/verify && composer install
composer stan     # PHPStan level max
composer smoke    # 17 поведенческих проверок
PGHOST=... PGUSER=... composer db   # миграции + резерв + купоны + конкуренция на PostgreSQL
bash ../../prototype/run.sh          # E1–E8 (нужен psql/pgbench)
```
