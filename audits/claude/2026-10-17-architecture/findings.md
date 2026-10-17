# Реестр находок

Шкала: **High** — ограничение архитектуры или дефект, который заставит хостов форкать/обходить, либо
нарушает собственную модель доверия; **Medium** — заметный долг или пробел с обходным путём; **Low** —
улучшение; **Info** — наблюдение. Критичных (порча данных, нарушение инварианта в штатном сценарии) не найдено.

Волна — из [08-roadmap.md](08-roadmap.md). «Дубль» — та же проблема, описанная с точки зрения пакета.

| ID | Sev | Кратко | Где (file:line) | Рекомендация | Волна |
|---|---|---|---|---|---|
| ARC-01 | High | Ядро знает домены: закрытые якоря, `promotionId/couponCode`, свёртка акций | `core/src/Quotes/Enums/QuoteAnchor.php`; `core/src/Quotes/Data/Adjustment.php:31-32`; `QuoteResult::appliedPromotions` | нейтральные фазы + стадии с `after/before`; `AdjustmentOrigin`; свёртку — в promotions | W4 |
| ARC-02 | High | Порты, реализуемые хостами, помечены только `#[Api]` | `core/src/Ports/*/Contracts/*.php` | `#[Spi(Implement)]` + правило SemVer для implementable | W0 |
| ARC-03 | High | Порты склада/лимитов только локально-транзакционные; внешний склад невозможен | `core/src/Ports/Stock/Contracts/StockReservations.php` | Remote-протокол hold/confirm/cancel через outbox | W4 |
| ARC-04 | Medium | Существительные в трёх местах; запись в чужие таблицы не проверяется | `*ServiceProvider::NOUNS`; `tests/Arch/ownership.php` | единый источник в `composer.json extra`; gate на `table('…')` | W1 |
| ARC-05 | Medium | Правило раскладки нарушено в ядре; helpers в `Data` как Api; `Unit` и `@internal`, и `#[Api]` | `core/src/Measures/Unit.php:10`; `Stores/Data/Codes.php` и др. | узаконить или перенести; gate раскладки | W0/W1 |
| ARC-06 | Medium | 6 копий `Clock`, 6 копий `Rows`, однотипные `*Config` | `packages/*/src/Common/Internal/{Clock,Rows}.php` | публичный `Commerz\Support` | W1 |
| ARC-07 | Medium | Service locator (`Container::getInstance`, `make` в стадиях) | `core/src/Plugins/CommerzPlugins.php:23`; `catalog/src/Variants/Internal/VariantSellables.php:79`; `core/src/Quotes/Internal/BaseStage.php:49` | `$this->app`; ленивые вклады (EXT-01) | W1 |
| ARC-08 | Low | `Sales` — ленивый локатор модулей | `sales/src/Sales.php:28-58` | фабрики-замыкания / deferred provider | W1 |
| ARC-09 | Low | Нет read-моделей для Eloquent-хостов | — | `commerz-eloquent` read-only | — |
| KRN-01 | High | Стадия `After Totals` может подменить итоги; конвейер их не проверяет | `core/src/Quotes/Data/QuoteState.php:222`; `core/src/Quotes/Internal/StagedQuotePipeline.php:59` | settlement в конвейере + сверка сумм | W0 |
| KRN-02 | High | Нет положительных начислений (сборы/наценки) | `core/src/Quotes/Data/Adjustment.php:34` | `Charge`, фаза `Fees`, kind `fee_rule` | W2 |
| KRN-03 | High | Цена единицы — целые минорные; шаг продажи не реализован (а документирован) | `core/src/Quotes/Data/QuoteLine.php`; `docs/architecture/data.md:49-50` | `UnitPrice(amount, per)`, `QuantityRules` | W2 |
| KRN-04 | Medium | 57 валют, расширения нет | `core/src/Money/Currency.php:15` | полный ISO 4217 + `commerz.currencies` | W1 |
| KRN-05 | Medium | Нет абстракции часов | 6× `Common/Internal/Clock.php`, 43 файла `new DateTimeImmutable` | PSR-20 в ядре + фейк | W1 |
| KRN-06 | Medium | `Uuid7` — статическое состояние | `core/src/Identity/Uuid7.php` | `IdGenerator` | W1 |
| KRN-07 | Medium | `CommerceContext` не расширяем (нет store, tz, атрибутов) | `core/src/Context/CommerceContext.php:27` | типизированные атрибуты контекста | W1 |
| KRN-08 | Medium | Outbox: нет DLQ, общий fan-out, аренда 300 с на пакет | `core/src/Outbox/Internal/OutboxRelay.php:28-32` | доставка на потребителя, `max_attempts`, аренда на сообщение | W3 |
| KRN-09 | Medium | Outbox пишет только sales; каталог/цены/акции — не durable | `sales/src/**` vs остальные пакеты | integration events во всех пакетах | W3 |
| KRN-10 | Medium | O(L·(L+A)) в `QuoteState` | `core/src/Quotes/Data/QuoteState.php:56-84` | индексы и мемоизация | W1 |
| KRN-11 | Low | `Adjustment(Order, lineId)` — двойной смысл | `core/src/Quotes/Data/Adjustment.php` | тип `AllocatedShare` | W1 |
| KRN-12 | Low | `lock_timeout` захардкожен, нет `statement_timeout` | `sales/src/Checkout/Internal/OrderPlacement.php` (`lockTimeoutMs: 3000`) | конфиг `commerz.operations.*` | W1 |
| KRN-13 | Low | Коды ошибок не каталогизированы кодом | `packages/*/src/**/Exceptions` | генерация каталога, check в CI | W3 |
| KRN-14 | Low | `ContextResolver` никто не вызывает | `core/src/Context/Contracts/ContextResolver.php` | удалить до 1.0 или в `commerz-http` | W0 |
| KRN-15 | Low | Пробелы API `Money` | `core/src/Money/Money.php` | `min/max/abs`, умножение на дробь, JSON | W1 |
| EXT-01 | High | Все вклады создаются при компиляции; сломанный адаптер роняет реестр | `core/src/Plugins/Internal/PluginGraphCompiler.php:231` | атрибут `#[Contribution]`, ленивый `get()` | W1 |
| EXT-02 | Medium | `key()` по `method_exists` | `core/src/Plugins/Internal/PluginGraphCompiler.php:248` | базовый интерфейс/атрибут | W1 |
| EXT-03 | Medium | `requires` без диапазонов версий, SPI без версии | `core/src/Plugins/PluginManifest.php` | `id => constraint`, версия kind | W4 |
| EXT-04 | High | Нет participants размещения, свойств строки, метаданных, ручной цены, cart policies, holds | `sales/src/Checkout/Internal/OrderPlacement.php`; `sales/src/Orders/Data/DraftLine.php:14`; `sales/src/Carts/Data/AddCartLine.php:15` | см. roadmap §5 | W2/W4 |
| EXT-05 | Medium | Скрытая сессия акций в `WeakMap` | `promotions/src/Evaluation/Internal/PromotionSessions.php:19` | вложения `QuoteState` | W1 |
| EXT-06 | Medium | Правила акций: только AND, нет подарка/комплекта/ступеней/фактов клиента | `promotions/src/Rules/**` | композиция, `CustomerProfile::facts`, `GiftLine` | W2 |
| EXT-07 | Low | Документированная стратегия `acme.nearest` невыразима | `inventory/src/Allocation/Data/AllocationRequest.php` | hint с адресом (PKG-INV-01) | W2 |
| CFG-01 | High | Политики глобальны, а не по магазину/каналу; шлюз не знает магазина | `pricing/config/commerz-pricing.php`; `inventory/config/…`; `sales/config/…`; `sales/src/Gateways/Data/GatewayPaymentRequest.php:24` | каскад политик | W4 |
| CFG-02 | Medium | Захардкоженные параметры outbox/lock/веса | `OutboxRelay.php:28-32`; `CartQuoter.php:79` | в конфиг | W1 |
| CFG-03 | Low | `doctor` не валидирует конфиги пакетов и выбранные ключи | `core/src/Internal/Console/DoctorCommand.php` | резолв всех `*Config` и ключей | W0 |
| PKG-CAT-01 | Medium | Нет полнотекста/сортировок/фасетов и SPI поиска | `catalog/src/Products/Data/ListProducts.php` | `search_backend` SPI + outbox | W3 |
| PKG-CAT-02 | Medium | `tax_class` — свободная строка | `catalog/database/migrations/…variant_tables.php` | doctor-сверка, `TaxClassRef` | W1 |
| PKG-CAT-03 | Medium | Габариты не попадают в снимок | `core/src/Ports/Sellables/Data/SellableSnapshot.php` | `dimensions` в снимке | W2 |
| PKG-CAT-04 | Low | `handle` не локализован | `catalog/…product_tables.php` | таблица slug по локали | — |
| PKG-CAT-05 | Low | Нет имени/медиа у варианта, нет порта медиа | — | `MediaRefs` порт | — |
| PKG-CAT-06 | Low | `DatabaseVariants` 677 строк | `catalog/src/Variants/Internal/DatabaseVariants.php` | декомпозиция | — |
| PKG-INV-01 | High | У локации нет адреса; бронь не знает назначения → нет BOPIS/ближайшего склада | `inventory/src/Locations/Data/CreateLocation.php`; `core/src/Ports/Stock/Data/ReserveStock.php:19` | `Location::address`, `AllocationHint` | W2 |
| PKG-INV-02 | Medium | Стратегия аллокации глобальна | `inventory/config/commerz-inventory.php` | каскад политик | W4 |
| PKG-INV-03 | Medium | Нет ожидаемых поступлений/предзаказа | — | `incoming`, `available_at` | — |
| PKG-INV-04 | Low | 400–630-строчные классы протоколов | `inventory/src/Reservations/Internal/DatabaseReservations.php` | выделить протокол | — |
| PKG-PRC-01 | High | Дубль KRN-03 | | | W2 |
| PKG-PRC-02 | Medium | Освобождение от налога оставляет брутто; нет reverse charge/VAT ID | `pricing/src/Calculation/Internal/TaxStage.php:57` | политика `exemption`, `taxId` | W2 |
| PKG-PRC-03 | Medium | Налог только по адресу доставки/глобальной зоне | `pricing/config/commerz-pricing.php` | origin, зона канала | W4 |
| PKG-PRC-04 | Medium | Округление налога только построчно | `pricing/src/Calculation/Internal/RatesTaxCalculator.php` | `rounding_level` | W2 |
| PKG-PRC-05 | Low | Нет контрактных цен клиента | `pricing/src/Prices/Data/SetPrice.php` | назначение прайс-листа клиенту | — |
| PKG-PRM-01 | Medium | Все активные акции и правила грузятся на каждую котировку | `promotions/src/Evaluation/Internal/ActivePromotions.php:165-178` | фильтр купонных, кэш по revision | W3 |
| PKG-PRM-02 | Medium | `name` акции не локализован | `promotions/src/Definitions/Data/CreatePromotion.php` | `LocalizedText` | W2 |
| PKG-PRM-05 | Low | `DatabasePromotionDefinitions` 549 строк | `promotions/src/Definitions/Internal/DatabasePromotionDefinitions.php` | декомпозиция | — |
| PKG-SHP-01 | High | Нет живых тарифов перевозчика | `shipping/src/Rates/Contracts/DeliveryRate.php` | `rate_quote_provider` + снимок тарифа на корзине | W2 |
| PKG-SHP-02 | High | Нельзя выбрать ПВЗ | `sales/src/Carts/Data/SelectDelivery.php:14` | `pickupPointRef`, options | W2 |
| PKG-SHP-03 | Medium | `BookShipment` без отправителя, состава, магазина | `core/src/Ports/Delivery/Data/BookShipment.php:17` | расширить DTO | W2 |
| PKG-SHP-04 | Medium | Неизвестный вес = 0 молча | `sales/src/Common/Internal/CartQuoter.php:79` | политика `unknown_weight` | W1 |
| PKG-SHP-05 | Low | Нет многоместных/раздельных отправлений | `core/src/Ports/Delivery/Data/Parcel.php` | `list<Parcel>` | — |
| PKG-SAL-01 | High | Дубль EXT-04 (одна строка на sellable, нет свойств) | `sales/src/Carts/Data/AddCartLine.php:15` | | W2 |
| PKG-SAL-02 | High | CRM-черновик без ручной цены/скидки | `sales/src/Orders/Data/DraftLine.php:14` | команды Draft + стадия `sales.manual` | W2 |
| PKG-SAL-03 | High | Запрос к шлюзу без store/channel/строк/налогов (54-ФЗ) | `sales/src/Gateways/Data/GatewayPaymentRequest.php:24` | `PaymentContext` | W2 |
| PKG-SAL-04 | Medium | Бронируются только `Physical` — решение не там | `sales/src/Checkout/Internal/OrderPlacement.php:273` | передавать все строки складу | W2 |
| PKG-SAL-05 | Medium | Одна пропавшая позиция валит котировку корзины | `sales/src/Common/Internal/CartQuoter.php` | `Rejection(line_unavailable)` | W2 |
| PKG-SAL-06 | Medium | Нет capture/void/частичного capture/споров | `sales/src/Gateways/Contracts/PaymentGateway.php` | аддитивные методы | W3 |
| PKG-SAL-07 | Medium | Нет редактирования заказа | `sales/src/Orders/Contracts/Orders.php` | Order Edit | — |
| PKG-SAL-08 | Medium | Нет `expectedVersion` в командах корзины | `sales/src/Carts/Data/*` | опциональный параметр | W1 |
| PKG-SAL-09 | Low | `OrderPlacement` — 16 зависимостей | `sales/src/Checkout/Internal/OrderPlacement.php` | конвейер участников | W4 |
| PKG-SAL-10 | Low | Нет слияния/очистки брошенных корзин | — | команды + событие | — |
| PKG-TK-01 | Medium | Нет фейков gateway/carrier/tax | `packages/testkit/src/Fakes` | добавить | W1 |
| PKG-TK-02 | Medium | Нет построителей мира | `tests/EndToEnd/Support/ShopWorld.php` (не поставляется) | перенести в testkit | W1 |
| PKG-TK-03 | Low | Нет фейков часов/id | — | после KRN-05/06 | W1 |
| DAT-01 | Medium | Порядок блокировок — соглашение | `docs/architecture/invariants.md` | lock-order guard в testing | W3 |
| DAT-02 | Medium | Снимки заказа в JSONB — тяжёлая аналитика | `sales/database/migrations/…order_tables.php` | нормализованные таблицы снимка | W3 |
| DAT-03 | Medium | Нет стратегии роста журналов | movements, order_events, payment_events | партиционирование, архив | W3 |
| DAT-04 | Low | Курсор не подписан и не привязан к запросу | `core/src/Pagination/Cursor.php` | HMAC + хэш запроса | W1 |
| DAT-05 | Low | UUIDv7 зависит от часов узла | `core/src/Identity/Uuid7.php` | задокументировать | W0 |
| DAT-06 | Low | Outbox prune по TTL receipts | `core/src/Operations/Internal/Console/PruneOperationsCommand.php` | своя политика | W3 |
| DAT-07 | Info | Schema-per-tenant работает через `current_schema()` | `core/src/Schema/Internal/SchemaFingerprint.php` | документировать и тестировать | W0 |
| QA-01 | Medium | CI не ставит пакеты изолированно, не гоняет consumer-smoke и PG16 | `.github/workflows/ci.yml` | jobs isolated/consumer, PG 16/17, prefer-lowest, audit | W0 |
| QA-02 | Medium | Тесты пакетов вне PHPStan | `phpstan.neon` | добавить пути | W0 |
| QA-03 | Medium | Нулевая наблюдаемость | `packages/*/src` | порт `Telemetry`, PSR-3 | W3 |
| QA-04 | Low | Нет мутационного тестирования арифметики | — | Infection на Money/Allocator/Totals | W3 |
| QA-05 | Low | Миграции не публикуются | `packages/*/src/*ServiceProvider.php` | `publishesMigrations` + opt-out | W1 |
| QA-06 | Low | Тяжёлый исследовательский корпус в монорепо | `docs/discovery`, `plans/archive/**/review` | вынести | — |
| DOC-01 | Medium | Дрейф доков от кода (8 мест) | см. 07 §3 | правки + doc-check в CI | W0 |
| DOC-02 | Low | D36–D38 только в архиве плана | `plans/archive/…/decisions/D36-38.json` | перенести в `decisions.md` | W0 |
| DX-01 | Medium | Нет пресетов установки | `core/src/Internal/Console/InstallCommand.php:12-29` | `--preset=shop|crm|pim` | W1 |
| DX-02 | Medium | Нет wire-формата ошибок/VO | `core/src/Errors/CommerzException.php` | `toProblem()`, `JsonSerializable` | W1 |
| DX-03 | Info | Фасады с `@method` — ок | `sales/src/Facades/Sales.php` | генерировать | — |
| DX-04 | Low | Длинные конструкторы команд | `promotions/src/Definitions/Data/CreatePromotion.php` | builders | — |

Итого 89 строк: High — 16 (из них 2 дубля, 14 уникальных), Medium — 44, Low — 27, Info — 2.
