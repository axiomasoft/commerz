# 08. Целевая архитектура, идеи и дорожная карта

Принцип отбора: сохранить сильные стороны (инварианты в PostgreSQL, явные протоколы, типизация, SemVer-
дисциплина) и снять ограничения, которые заставят хостов форкать. Всё ниже — **аддитивно** для 0.x, кроме
явно помеченного «BC» (допустимо до 1.0 при записи решения).

## 1. Порядок работ (волны)

| Волна | Цель | Находки | Оценка |
|---|---|---|---|
| **W0 — гигиена (1–2 дня)** | закрыть дефекты и дрейф | KRN-01, DOC-01, DOC-02, ARC-02 (маркеры), ARC-05 (`Unit`), QA-01 (consumer job, PG16), QA-02 | S |
| **W1 — фундамент ядра (1–2 нед.)** | убрать блокеры для расширения | EXT-01 (ленивые вклады), KRN-05 (Clock), KRN-06 (IdGenerator), ARC-06 (Support), KRN-07 (атрибуты контекста), EXT-05 (вложения состояния), KRN-10 (индексы QuoteState) | M |
| **W2 — коммерческие блокеры (2–4 нед.)** | магазин РФ + Flex CRM без форка | KRN-02 (сборы), KRN-03 (цена за единицу + правила количества), EXT-04 (свойства строки, метаданные, ручная цена черновика), PKG-SAL-03 (PaymentContext), PKG-SHP-01/02 (живые тарифы, ПВЗ), PKG-INV-01 (адрес локации, hint брони) | L |
| **W3 — масштаб и эксплуатация (2–3 нед.)** | прод под нагрузкой | KRN-08 (outbox v2), KRN-09 (outbox во всех пакетах), QA-03 (телеметрия), PKG-PRM-01 (кэш акций), DAT-01 (lock guard), DAT-02/03 | M–L |
| **W4 — модель ядра 1.0 (BC, 3–4 нед.)** | OCP ядра | ARC-01 (фазы вместо якорей, origin), ARC-03 (remote-протокол портов), CFG-01 (каскад политик), EXT-03 (версии SPI), EXT-04 (participants, holds) | L |

## 2. W0 — конкретные правки

1. **KRN-01.** `QuoteState::withTotals` → `@internal` + проверка в конвейере:
   ```php
   // StagedQuotePipeline::run(), после цикла стадий
   $totals = $state->totals ?? throw new QuoteFailed('totals', 'totals_missing', '…');
   SettlementCheck::assert($input, $state);   // строки = входные строки; Σ line.total + delivery − … == total
   ```
   и запрет `After Totals` (кроме стадий, которые меняют только rejections — проверяется сравнением сумм до/после).
2. **ARC-02.** Добавить `#[Spi(kind: SpiKind::Implement, since: '0.1')]` к портам; правило в `semver-policy.md`;
   `ApiManifest` хранит `implementable: true` и `api:check` падает на добавлении абстрактного метода.
3. **DOC-01/02.** Перенести D36–D38 в `decisions.md`; исправить README (D1–D38), `extension.md:77-79`,
   `packages.md:97`, `data.md:49-50` (или реализовать в W2); добавить `scripts/check-docs.php` в CI.
4. **QA-01.** В CI: `postgres: [16, 17]`, job `consumer` с `scripts/test-consumer-install.sh`, `composer audit`.

## 3. Целевая модель конвейера котировки (W1 + W4)

```text
Фазы ядра (фиксированы, нейтральны):   Pricing → Discounts → Shipping → Fees → Tax → Settlement
Стадии пакетов (данные, топосортировка):
  base(Pricing,at) · promotions.catalog(Discounts) · promotions.cart(Discounts, after: promotions.catalog)
  delivery(Shipping,at) · promotions.delivery(Shipping, after: delivery) · fees(Fees) · tax(Tax,at)
Settlement — не стадия, а финальный шаг конвейера (KRN-01).
```

```php
interface QuoteStage {                       // SPI v2 (BC до 1.0 или параллельный StageV2)
    public function key(): string;
    public function phase(): QuotePhase;     // enum из 6 нейтральных фаз
    /** @return list<string> */ public function after(): array;   // ключи стадий
    /** @return list<string> */ public function before(): array;
    public function apply(QuoteState $state): QuoteState;
}

final readonly class AdjustmentOrigin {      // вместо promotionId/couponCode
    public function __construct(public string $kind, public ?string $ref = null, public array $attributes = []) {}
}
// promotions: new AdjustmentOrigin('promotion', 'promotion:'.$id, ['coupon' => 'AUTUMN'])

final readonly class Charge {                 // KRN-02
    public function __construct(public AdjustmentTarget $target, public ?string $lineId, public Money $amount,
        public string $source, public string $explanation, public ?string $taxClass = null, public ?AdjustmentOrigin $origin = null) {}
}

// Вложения состояния (EXT-05): явный канал между стадиями, не входит в fingerprint
$state = $state->withAttachment($session);            // PromotionSession
$session = $state->attachment(PromotionSession::class);
```

## 4. Ленивый реестр вкладов (W1, EXT-01/02)

```php
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Contribution { public function __construct(public string $kind, public string $key) {} }

// Компиляция: только рефлексия атрибута + проверка интерфейса, без make()
final readonly class ContributionDescriptor { public function __construct(public string $kind, public string $key, public string $class, public ?object $instance = null) {} }

final class LazyContributions implements Contributions {
    /** @var array<string, array<string, object>> */ private array $made = [];
    public function get(string $kind, string $key, string $interface): object {
        return $this->made[$kind][$key] ??= $this->make($this->descriptor($kind, $key), $interface); // ошибка → UnsupportedContribution
    }
}
```

Эффект: нормальный конструкторный DI во всех вкладах (уходят `$container->make()` из `BaseStage`, `TaxStage`,
`VariantSellables`, `PromotionSessions`), сломанный адаптер не роняет котировку, первый запрос дешевле.
Ключ из `key()` остаётся как fallback для обратной совместимости 0.1 (deprecated).

## 5. Участники размещения и удержания (W4, EXT-04)

```php
#[Spi(kind: SpiKind::Implement, since: '0.3')]
interface PlacementParticipant {
    public const string KIND = 'placement_participant';
    public function key(): string;
    public function slot(): PlacementSlot;               // AfterStock | AfterPromotions | BeforePayments — место в глобальном порядке блокировок
    public function prepare(PlacementDraft $draft): ?PreparedParticipation;   // вне транзакции: чтение, валидация
    public function apply(PlacementContext $ctx, PreparedParticipation $p): void; // внутри транзакции, без сети, свои таблицы
    public function release(OrderRef $order, ReleaseReason $reason): void;    // отмена/истечение, идемпотентно
}
```

Подарочные карты, бонусы, кредитный лимит B2B — отдельные пакеты без изменения sales. Встроенные
`reserve` и `consume` становятся первыми двумя участниками (dogfooding, как с плагинами).

`OrderHold` — именованное удержание (`fraud_review`, `b2b_approval`, `stock_pending` для ARC-03), блокирующее
`initiate`/`create fulfillment` до `releaseHold`; производное состояние `OrderView::holds`.

## 6. Каскад политик (W4, CFG-01)

```php
#[Api] interface Policies {
    /** @template T of object  @param class-string<T> $policy  @return T */
    public function for(string $policy, ChannelId $channel): object;
}
// Пакет объявляет: $registrar->declarePolicy(SalesPolicy::class, defaults: fn (SalesConfig $c) => …);
// Данные: cmz_channel_policies(channel_id, policy_key, version, document jsonb) с кодеком пакета.
```

Чтение кэшируется на процесс с инвалидацией по `ChannelUpdated`/`PolicyChanged`. Политики — типизированные
`final readonly` с валидацией в конструкторе, как сейчас `*Config`.

## 7. Протокол удалённых портов (W4, ARC-03)

```text
prepare (вне tx):  RemoteStock::hold(demand 'order:{id}', lines, ttl) → HoldToken      (идемпотентно по demand)
tx:                order + participants + outbox(stock.confirm_requested)               (локально, без сети)
after commit:      relay → RemoteStock::confirm(token)                                  (ретраи, unknown → reconcile)
rollback / expiry: RemoteStock::cancel(token)                                           (компенсация)
```

Sales выбирает протокол по реализации (`LocalStockReservations` vs `RemoteStockReservations`). Тот же шаблон
пригоден для внешней лояльности и внешних подарочных карт.

## 8. Идеи сверх находок

1. **Executable spec инвариантов.** Каждая строка `invariants.md` → атрибут `#[Invariant('INV-02')]` на тесте;
   скрипт проверяет, что у каждого инварианта есть тест, и генерирует таблицу в документацию.
2. **Golden-файлы котировок.** Корпус `QuoteInput → QuoteResult` в JSON (часть уже есть как G1–G21) как
   публичный fixture-набор в testkit: авторы стадий/правил прогоняют свои плагины против эталона.
3. **Детерминированный «replay» заказа.** `Orders::replayQuote(OrderId)` — пересчитать котировку по снимку
   входа (фиксируемому в заказе) с теми же версиями стадий → доказательство для поддержки «почему такая сумма».
   Для этого сохранять `QuoteInput` (нормализованный) рядом с `quote_fingerprint`.
4. **Explain для всего конвейера.** Уже есть explain цены, акций, доставки — объединить в
   `Commerz::quotes()->explain(QuoteInput)` с трассой по стадиям (что каждая изменила).
5. **Read-only Eloquent-мост** (`commerz-eloquent`) для админок и Scout (ARC-09).
6. **Сигнатуры событий как контракт.** Каталог integration events (`type`, `version`, JSON Schema payload)
   генерируется из кода и публикуется, `api:check` следит за совместимостью payload.
7. **Политика округления цен** (психологические цены `…99`) как `price_rounding` kind при конвертации валют.
8. **Сборка через ядро «семейства» без feature-пакетов в тестах хоста.** `Commerce::fake()` — один вызов,
   подменяющий все порты фейками testkit, с утверждениями (`assertReserved`, `assertUsageConsumed`).
9. **Feature flags для SPI.** Новые kinds/методы за флагом конфигурации на один minor — мягкая миграция
   экосистемы плагинов.
10. **Схемы метаданных.** Для `attributes` корзины/заказа — переиспользовать `attribute_type` каталога
    (тот же кодек и валидация), чтобы метаданные были типизированы, а не «мешком».

## 9. Чего делать не надо

- Не возвращать Eloquent в запись и не вводить подмену моделей — single-writer и протоколы блокировок дороже.
- Не добавлять generic before/after-хуки — точечные kinds (participants, policies, fees) покрывают нужное без
  потери предсказуемости.
- Не разрешать сеть в стадиях котировки ради живых тарифов — снимок тарифа на корзине (PKG-SHP-01) сохраняет
  чистоту и воспроизводимость.
- Не делить ядро на много мелких пакетов (`commerz-money`, `commerz-plugins`): связность ядра высокая и
  оправданная; выносить стоит доменные порты (ARC-01), а не инфраструктуру.
