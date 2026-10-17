# 02. Ядро (`axiomasoft/commerz`)

Прочитаны целиком: `CommerzServiceProvider`, `Commerz`, `PluginGraph`, `Configuration/*`, `Plugins/**`,
`Quotes/**`, `Money/*`, `Measures/*`, `Identity/*`, `Context/*`, `Errors/*`, `Operations/**`, `Outbox/**`,
`Schema/**`, `Ports/**` (контракты и DTO), миграции ядра. Находки `KRN-xx`.

## 1. Сильные стороны

- **Деньги без float.** `Money(int minor, Currency)`, проверка переполнения int64 на каждой операции,
  `Money::of()` отказывается округлять лишние знаки, `brick/math` для процентов, `includedPart()` для выделения
  налога из брутто, `MoneyAllocator` — метод наибольшего остатка с детерминированной развязкой по индексу.
- **Иммутабельность и типизация.** Почти все DTO `final readonly`, инварианты формы в конструкторах, именованные
  аргументы, нет «массивов-конфигов» в сигнатурах.
- **Транзакции и идемпотентность.** `Transactions::run` с savepoint-семантикой (D20) и защитой от бага Laravel
  с deadlock во вложенной транзакции (`DatabaseTransactions.php`, комментарий про decrement уровня);
  `OperationReceipts::once` — `INSERT … ON CONFLICT DO NOTHING RETURNING` без read-then-write, повтор с другим
  payload → `IdempotencyConflict`.
- **Каноничный `Fingerprint`.** Рекурсивная сортировка ключей, различие `{"0":…}` и `[…]`, enum/Stringable.
- **Схема.** Единый `TableNames`, детерминированное укорачивание имён ограничений до 63 байт, `EXCLUDE` через
  helper, отпечаток префикса в `cmz_schema_state` с поиском «чужого» префикса в той же схеме.
- **Реестр плагинов.** Топосортировка, проверка владения kind по транзитивным `requires`, уникальность ключей,
  заморозка, понятные ошибки с указанием источника (`commerz.plugins.2` / `builtin <class>`).
- **Конвейер котировки.** Неизменяемый `QuoteState`, «любое изменение сумм после totals сбрасывает totals»,
  fingerprint результата включает версии стадий.

## 2. Находки

### KRN-01 · High · Модель доверия конвейера: любая стадия может подменить итоги

`QuoteState::withTotals(array $lines, QuoteTotals $totals)` — публичный метод `#[Api]`-класса
(`QuoteState.php:222`). Конвейер после всех стадий проверяет только, что `totals !== null`
(`StagedQuotePipeline.php:59`). Значит, стадия хоста/плагина в позиции `After Totals` может вызвать
`withTotals(...)` с произвольными строками и итогами — и `Checkout::place` разместит заказ с подменёнными суммами
(вплоть до нулевых): `expectedTotal` сравнивается с тем же подменённым quote, а `OrderWriter` не сверяет снимок
с входными строками (`packages/sales/src/Common/Internal/OrderWriter.php`, проверяется только валюта).
Позиция `After` у якоря `Totals` разрешена: проверка «один `At` на якорь» её не касается.

Это противоречит собственной модели доверия: promotions **не доверяют** плагинам (`DiscountGuard`, PRM-05,
`RuleTrustTest`), shipping валидирует `RatePrice`, pricing — `TaxResultValidator`, а ядро доверяет любой стадии
без проверок. «Plugins are trusted in-process code» снимает вопрос злого умысла, но не ошибок.

Исправление (минимальное, без BC-взрыва в 0.x):

```php
// QuoteState: totals может установить только конвейер
/** @internal */
public function withTotals(array $lines, QuoteTotals $totals, SettlementToken $token): self

// StagedQuotePipeline::run — settlement не стадия, а финальный шаг конвейера
$state = $this->settle($state);           // TotalsStage::apply → внутренний Settler
$this->assertConsistent($state);          // Σ settled.total == totals.total, Σ налогов, Σ скидок
```

и запретить стадии в позиции `After Totals` (или разрешить им только `withRejection`/аннотации).

### KRN-02 · High · Нет положительных начислений (сборов/наценок) — только скидки и доставка

`Adjustment` обязан быть отрицательным (`Adjustment.php:34`), единственное положительное начисление —
`DeliveryCharge`. Невыразимы: комиссия за наложенный платёж, сбор за упаковку/подарочную упаковку,
«малый заказ», сервисный сбор, залог тары, экосбор, страховка отправления, надбавка за срочность. Это базовая
потребность и магазинов, и CRM (услуга «выезд мастера»).

Рекомендация: тип `Charge` (`target: order|line|delivery`, `amount > 0`, `taxClass`, `source`, `explanation`),
`QuoteState::withCharge()`, фаза `Fees` перед `Tax`; `TotalsStage` и `TaxStage` учитывают charges как
облагаемую базу. Сборы — новый contribution kind `fee_rule` (по образцу `delivery_rate`).

### KRN-03 · High · Цена единицы — целые минорные единицы; «весовой товар с шагом» не реализован

`ResolvedPrice::$unit` — `Money`, `QuoteLine::subtotal() = unitPrice × quantity`. Для товара «499,99 ₽/кг»,
продаваемого в граммах (как обещает `data.md:49-50`: *«Weighted and metered goods sell in grams or millimetres
with a sale step»*), цена грамма = 49,999 коп. — непредставима. Поиск по коду (`sale step|saleStep|price_unit|
quantity_step`) — ни одного совпадения: **обещание документации не реализовано**.

Рекомендация:

```php
final readonly class UnitPrice {           // цена за N базовых единиц
    public function __construct(public Money $amount, public int $per = 1) {}  // 49999 коп / 1000 г
    public function lineTotal(int $quantity, RoundingMode $mode): Money;       // округление только в итоге строки
}
final readonly class QuantityRules { public int $min; public ?int $max; public int $step; }  // в SellableSnapshot
```

`Carts::addLine` проверяет `QuantityRules`, `TotalsStage` округляет строку один раз.

### KRN-04 · Medium · Закрытый список из 57 валют без расширения

`Currency::EXPONENTS` (`Currency.php:15`) — 57 кодов. Нет, например, QAR, MAD, TWD, PHP, MYR, NGN, KES, COP,
PEN, BDT, PKR, LKR, MNT, PYG, DOP, GTQ, ALL, MKD, BAM. Нет способа объявить внутреннюю «валюту» (бонусные баллы,
токены B2B-кредита). Магазин в Катаре или Марокко невозможен без форка ядра.

Рекомендация: полная таблица ISO 4217 (сгенерированная, с тестом на актуальность) + `commerz.currencies`
(`'PTS' => 0`) для пользовательских кодов; `Currency::of()` читает реестр, а не константу.

### KRN-05 · Medium · Нет абстракции часов

Шесть идентичных `Common/Internal/Clock::now()` в пакетах, 43 файла с `new DateTimeImmutable`, в SQL — `now()`
(relay, prune). Последствия: тесты не могут заморозить время (сроки оплаты, окна цен/акций, истечение броней
проверяются через реальное время или подстановку `context.at`), время PHP и БД может расходиться.

Рекомендация: `Psr\Clock\ClockInterface` в ядре (`Commerz\Support\Clock`), биндинг на системные часы, фейк в
testkit (`Commerce::freezeTime()`); SQL получает время параметром там, где оно влияет на бизнес-решение.

### KRN-06 · Medium · `Uuid7` — глобальное статическое состояние

`Uuid7::fixed()` / `reset()` — статика (`Identity/Uuid7.php`); забытый `reset()` протекает между тестами,
параллельные тесты одного процесса не изолированы. Рекомендация: интерфейс `IdGenerator` с биндингом в
контейнере, `EntityId::generate()` делегирует ему; статический путь оставить как deprecated-обёртку.

### KRN-07 · Medium · `CommerceContext` не расширяем

Поля: channel, currency, locale, customer, customerGroups, at. Нет: `store`, часового пояса (бизнес-даты),
страны/региона для налога без адреса (POS, цифровые товары — сейчас глобальный `default_zone`), и **нет
расширяемого набора атрибутов**. Плагин-цена «по договору B2B-клиента», условие акции «терминал POS №3»,
налоговый калькулятор «по юрлицу продавца» не могут получить свои данные из контекста.

Рекомендация:

```php
$ctx = $ctx->with(new B2bContract('contract:77'));       // типизированный атрибут, ключ — FQCN
$contract = $ctx->get(B2bContract::class);               // ?B2bContract
```

Атрибуты — `final readonly`, `JsonSerializable`, входят в fingerprint котировки и в снимок корзины.

### KRN-08 · Medium · Outbox: нет DLQ, общий fan-out, фиксированная аренда пакета

`OutboxRelay` (`Outbox/Internal/OutboxRelay.php`):

1. **Нет предела попыток и мёртвой очереди.** Ошибочное сообщение ретраится бесконечно (backoff до 1 ч);
   нет состояния `parked`, нет команды повторной доставки.
2. **Fan-out «всё или ничего».** Сообщение доставляется Laravel-событию и всем `outbox_consumer` в одном цикле;
   падение одного потребителя заново доставит событие **всем** (и тем, кто уже обработал). Идемпотентность
   потребителей документирована, но объём дублей растёт с числом потребителей, а «ядовитый» потребитель
   держит сообщение недоставленным для всех.
3. **Аренда 300 с на весь пакет.** Пакет до 1000 сообщений захватывается одним `UPDATE … available_at = now() +
   300s`, затем доставляется последовательно. Если доставка пакета длится дольше 300 с (медленный HTTP-потребитель),
   второй relay захватит «хвост» того же пакета → **параллельная двойная доставка**. Константа не настраивается.
4. Prune удаляет доставленные сообщения по `receipt_ttl_days` — смешение двух политик хранения.
5. Нет метрик (лаг, возраст старейшего сообщения, число ретраев), нет LISTEN/NOTIFY — опрос раз в секунду.

Рекомендация: таблица `outbox_deliveries(message_id, consumer_key, state, attempts, available_at, last_error)` —
отдельная доставка на потребителя; аренда на сообщение (или продление после каждого); `max_attempts` →
`dead`; `commerz:outbox:retry {--consumer} {--id}`; конфиг `commerz.outbox.{lease_seconds, max_attempts,
retention_days}`; health-метрика для `commerz:doctor`.

### KRN-09 · Medium · Факты каталога, цен и акций не попадают в outbox

Outbox используют только core и sales. `ProductPublished`, `PricesChanged`, `PromotionChanged`,
`TaxRatesChanged` — только Laravel-события «после коммита», не durable (падение процесса между коммитом и
dispatch теряет событие). Обоснование «нет потребителя внутри семейства» верно для пакетов, но **главный
потребитель — хост**: поисковый индекс, кэш витрины, фид маркетплейса, синхронизация с 1С. Им нужна надёжная
лента изменений.

Рекомендация: в каждом пакете писать integration events (`catalog.product_published`, `pricing.prices_changed`
с `sellable_refs`, …) в outbox в той же транзакции; доменные Laravel-события оставить для удобства.

### KRN-10 · Medium · Квадратичные проходы в `QuoteState`

`line()` — линейный поиск (`QuoteState.php:56`), `lineNet()` — поиск строки + проход по всем adjustments
(`:62`), `itemsNet()` и `allocatedOrderAdjustments()` вызывают `lineNet` для каждой строки. Итог O(L·(L+A)) на
вызов; вызывается из `TotalsStage`, `TaxStage` и эффектов акций для каждого кандидата. При `max_lines = 250` —
терпимо, при допустимом конфигом `10000` (B2B-заказ) — секунды CPU на котировку.

Рекомендация: в `QuoteState` хранить индекс `lineId → position` и накопленные `lineNet` (пересчёт при
`withAdjustment` для одной строки); `allocatedOrderAdjustments()` мемоизировать в объекте состояния
(он иммутабелен — кэш безопасен).

### KRN-11 · Low · Двойной смысл `Adjustment(Order, lineId)`

`Order` с `lineId = null` — объявленная скидка на заказ, `Order` с `lineId` — доля, распределённая на строку.
`withAdjustment()` принимает обе формы, поэтому стадия может добавить «уже распределённую» долю, и она будет
распределена повторно в `allocatedOrderAdjustments()`. Рекомендация: отдельный тип `AllocatedShare` (только в
результатах settlement), `withAdjustment` отвергает `Order` с `lineId`.

### KRN-12 · Low · Транзакционные параметры не конфигурируемы

`lockTimeoutMs = 3000` захардкожен в сигнатуре и вызовах (`OrderPlacement::transact` и др.); нет
`statement_timeout`/`idle_in_transaction_session_timeout`. Рекомендация: `commerz.operations.lock_timeout_ms`
и `statement_timeout_ms` с дефолтами, переопределяемые вызовом.

### KRN-13 · Low · Коды ошибок не каталогизированы кодом

`code()` — строки, разбросанные по 200+ исключениям; `docs/reference/error-codes.md` ведётся отдельно.
Рекомендация: генерировать каталог кодов из исходников (как `php-api.json`) и проверять в CI — коды ошибок
являются wire-форматом для `commerz-http` (problem+json) и, следовательно, SemVer-поверхностью.

### KRN-14 · Low · `ContextResolver` — SPI, который никто не вызывает

`Context/Contracts/ContextResolver.php`: «The package never calls it implicitly». Grep: ни одного использования.
Мёртвый публичный контракт фиксирует SemVer-обязательство без пользы. Либо удалить до 1.0, либо перенести в
будущий `commerz-http`, где он реально нужен (middleware).

### KRN-15 · Low · Пробелы API `Money`

Нет `min/max/abs`, умножения на дробь (`multiplyBy(BigRational|string, RoundingMode)`), `allocate()` как метода,
форматирования (intl) и `jsonSerialize`. Мелочи, но каждый пакет и хост пишет их заново.

## 3. Отдельно о реестре плагинов (подробности — в [03-extension-and-configuration.md](03-extension-and-configuration.md))

- Создание **всех** вкладов при первой компиляции (`PluginGraphCompiler.php:231`) — EXT-01.
- `key()` определяется утиным типом `method_exists` (`:248`) — EXT-02.
- `requires` без диапазонов версий — EXT-03.

## 4. Оценка по оси

| Критерий | Балл |
|---|---|
| Value objects (деньги, количества, ссылки) | 9 |
| Транзакции, идемпотентность | 9 |
| Outbox | 6 |
| Конвейер котировки (дизайн) | 7 |
| Конвейер котировки (защищённость/расширяемость) | 5 |
| Тестируемость (время, id) | 5 |
