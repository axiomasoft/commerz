# **Глубокий архитектурный аудит и дорожная карта развития пакета Commerz до уровня коммерческой enterprise-платформы**

Текущая кодовая база commerz (v0.1.0, PHP ^8.3, Laravel ^11.0 / ^12.0) закладывает сильное архитектурное ядро, избегающее типичных проблем популярных PHP-решений: динамической мутации моделей и неиндексируемых JSON-атрибутов в Lunar, жёсткой привязки к Doctrine ORM в Sylius и отсутствия строгих конечных автоматов и блокировок в Bagisto и Vanilo1. Отказ от публичного экспонирования ORM-моделей в пользу иммутабельных final readonly DTO, использование сентинела Undefined для частичных обновлений, точная работа с деньгами в минорных единицах через bcmath, транзакционный DatabaseOutbox, вынос сетевых вызовов PaymentGateway за пределы БД-транзакций и звёздная топология пакетов (где доменные модули зависят только от commerz/core, а связываются через адаптеры в commerz/full) формируют правильный фундамент коммерческого уровня2. Эти базовые инварианты следует сохранить.  
Вместе с тем сквозная ревизия исходной архитектуры, схем миграций и API-контрактов всех девяти пакетов выявила **семь системных архитектурных дефектов** и крупный пласт недостающей функциональности, которые заблокируют развитие продукта и его встраивание в вашу CMS-экосистему (Unbox, Vaulter, Chatom, Azguard)2. Ключевые блокеры включают: глобальные UNIQUE-ограничения в БД без учёта тенанта/воркспейса; трёхстороннюю коллизию сущности Fulfillment между Inventory, Shipping и Sales; расхождение между строковыми ULID/UUID в DTO и автоинкрементными BIGINT в миграциях; привязку цен и складских остатков к сырой изменяемой строке sku вопреки задекларированному SellableRef; жёсткое ограничение количеств типом int; отсутствие межмодульных связей (Module Links) и компенсирующих саг; а также полное отсутствие пост-продажного OMS (редактирование заказов, физические RMA-возвраты, обмены, черновики заказов, подарочные карты, кошельки, подписки и B2B-квоты)2.  
Поскольку публичный API ещё не зафиксирован, оптимальная стратегия — провести до релиза v1.0 полную реструктуризацию границ модулей, нейминга и контрактов, опираясь на проверенные архитектурные механизмы **Medusa v2**, **Shopify Functions**, **commercetools**, **Vendure**, **Saleor**, **Shopware 6** и **Stripe PaymentIntents**3. Ниже приведена концентрированная техническая спецификация по всем слоям системы для прямой пошаговой проработки с AI-агентом.

## **1\. Ребрендинг, декомпозиция пакетов и выравнивание словаря API**

### **1.1. Оценка названия проекта, вендор-префикса и префиксов таблиц**

Текущее имя Commerz (commerz/\*, неймспейс Commerz\\) короткое и узнаваемое, но для коммерческого глобального продукта несёт два практических недостатка2. Во-первых, немецкоязычное написание через z в европейском финтех- и платёжном поле пересекается с банковским брендингом и вызывает фонетическую неоднозначность при устной передаче (commerce / commerz / kommerz). Во-вторых, ваши внутренние платформенные продукты — Unbox, Vaulter, Chatom, Azguard — выстроены в едином словообразовательном стиле двухсложных технических неологизмов на \-ox, \-er, \-om, \-ard2. Переход к фонетически однозначному имени вроде **Sellox** (sellox/core, Sellox\\, префикс таблиц slx\_ — прямая отсылка к центральной абстракции Sellable и рифма с Unbox), **Mercor** или **Cartex** устранит двусмысленность и впишет пакет в общую продуктовую линейку.  
Независимо от выбора имени необходимо исправить расхождение между документацией и миграциями в префиксации БД: руководство crm-embedding.md заявляет о конфигурируемом префиксе таблиц (по умолчанию commerz\_), однако реальные вызовы Schema::create('commerz\_inventory\_locations', ...) в миграциях пакетов содержат жёстко зашитую строку commerz\_2. Все миграции и Query Builder-запросы (DB::table(...)) должны резолвить имя таблицы через централизованный хелпер CoreSchema::table('products'), читающий config('commerce.database.table\_prefix', 'cmz\_') и опциональное имя подключения config('commerce.database.connection')2. Это позволит разводить таблицы коммерции и хост-CMS по разным схемам PostgreSQL или разным БД без правки кода пакетов.

### **1.2. Реструктуризация границ модулей и устранение коллизии Fulfillments**

В текущей топологии пакет commerz/sales оказался чрезмерно перегружен: объём его API-спецификации достигает 117,7 КБ против 50,9–85,7 КБ у остальных доменных пакетов (pricing, shipping, promotions, inventory, catalog), поскольку в него сведены корзины, пайплайн расчёта квот, чекаут, заказы, платёжные шлюзы, возвраты средств, налоги и корректировки1. При этом в кодовой базе возникла опасная **трёхсторонняя коллизия понятия Fulfillments**: одноимённые контракты, статусы и таблицы параллельно существуют в Commerz\\Inventory\\Fulfillments (DatabaseFulfillments списывает складские аллокации), Commerz\\Shipping\\Fulfillments (DatabaseFulfillments создаёт отправления у перевозчика) и Commerz\\Sales\\Fulfillments (commerz\_order\_fulfillments отслеживает исполнение строк заказа), что при конкурентных частичных отгрузках ведёт к гонкам и расхождению состояний2. Дополнительно внутренние утилиты Common\\Internal\\{Clock, Locks, Documents, Rows} продублированы в нескольких пакетах вместо размещения в core2.  
Для истинной встраиваемости по отдельным компонентам (например, подключение в CMS только связки pricing \+ tax \+ cart \+ payment для продажи цифровых подписок или услуг без установки catalog, inventory и логистики) целесообразно реорганизовать монорепозиторий в 10 атомарных доменных модулей над общим ядром и вынести интеграцию с CMS в отдельные пакеты-мосты1.

#### **Текстовая схема целевой модульной топологии и границ связности (Dual-Mode: Standalone Shop и CMS Module)**

## **\==================================================================================================== СЛОЙ 4: ХОСТ-СБОРКА И МОСТЫ ИНТЕГРАЦИИ (ВЫБИРАЕТСЯ ПО СЦЕНАРИЮ ВНЕДРЕНИЯ)**

# **\[Режим А: Standalone интернет-магазин\] \[Режим Б: Модуль внутри хост-CMS / экосистемы\] \+-- sellox/bundle (мета-сборка всех пакетов) \+-- sellox/bridge-unbox (workspace\_id, CMS Sellables) Регистрирует дефолтные адаптеры между \+-- sellox/bridge-vaulter (CDN Media, Digital Delivery, PII) доменными портами из коробки \+-- sellox/bridge-azguard (RBAC Policies, Security AuditLog) \+-- sellox/bridge-chatom (Chat Checkout, Messenger Events)**

# **\| \| v v**

## **СЛОЙ 3: МЕЖМОДУЛЬНАЯ ОРКЕСТРАЦИЯ И ГРАФ СВЯЗЕЙ (БЕЗ ПРЯМЫХ SQL JOIN И БЕЗ FOREIGN KEYS)**

\[WorkflowRunner (Саги с compensate())\] \[LinkRegistry (cmz\_entity\_links / CrossModuleQuery)\]

* PlaceOrderWorkflow \- catalog.product \<-\> unbox.article  
* ConfirmOrderChangeWorkflow \- orders.order \<-\> chatom.conversation  
* ProcessReturnRmaWorkflow \- catalog.variant \<-\> inventory.item (InventoryKit)  
  \====================================================================================================

# **\| \| v v**

## **СЛОЙ 2: ИЗОЛИРОВАННЫЕ ДОМЕННЫЕ ПАКЕТЫ (ЗАВИСЯТ ТОЛЬКО ОТ sellox/core, ZERO CROSS-PACKAGE IMPORTS)**

\[catalog\] \[pricing\] \[tax\] \[promotions\] \[stored-value\]  
(PIM, EAV, (Markets, (TaxClass, (Rules, Caps, (GiftCard,  
SearchPort) 30d Omnibus) VAT, External) BestCombo) Wallet Ledger)

# **\[inventory\] \[fulfillment\] \[payment\] \[cart\] \[orders\] \[subscriptions\] (Items, Kits, (Zones, PUDO, (Sessions, (QuotePipeline,(OMS, OrderEdit, (SellingPlan StockCommit) Parcels, RMA) Multicapture) StagedQuote) RMA, Exchange) Recurring Runner)**

# **\| v**

## **СЛОЙ 1: ЯДРО ПЛАТФОРМЫ (sellox/core) \+ СЕРТИФИКАЦИОННЫЙ ПАКЕТ (sellox/testkit)**

* Контекст и типы: TenantContext, StoreContext, ChannelContext, ActorRef, Money, Quantity, Measure  
* Абстракции товара: SellableRef(type, id), SellableProvider, SellingPlan  
* Расширяемость: PluginRegistry, ConfigurableOperation, CustomFieldRegistry, LinkRegistry, FailurePolicy  
* Надёжность БД: CoreSchema::table(), DatabaseOutbox, DatabaseIdempotency, DatabaseLockStrategy  
  \====================================================================================================

#### **Спецификация зависимостей composer.json и контрактных портов целевых пакетов v1.0**

| Целевой пакет | Зависимости composer.json (require) | Главный фасад / контракт | Реализуемые и потребляемые порты core | Ключевые события в DatabaseOutbox |
| :---- | :---- | :---- | :---- | :---- |
| core | php: ^8.3, illuminate/contracts, illuminate/database, illuminate/support | Contracts\\Core | Базовый поставщик Outbox, Idempotency, DatabaseLockStrategy, WorkflowRunner, LinkRegistry, CustomFieldRegistry | core.operation.failed, core.plugin.registered |
| catalog | core | Contracts\\Catalog | Реализует SellableProvider (catalog\_variant); определяет CatalogSearchPort, AssetResolverPort | catalog.product.upserted, catalog.product.published, catalog.variant.updated |
| pricing | core | Contracts\\Pricing | Реализует PriceResolverPort; потребляет CurrencyRatePort, TaxEnginePort (для витринного Gross/Net) | pricing.price\_list.updated, pricing.price.changed, pricing.staged.applied |
| tax | core | Contracts\\Tax | Реализует TaxEnginePort (встроенный калькулятор \+ адаптеры Stripe Tax / Avalara) | tax.exemption.verified, tax.rate.updated |
| promotions | core | Contracts\\Promotions | Реализует PromotionEvaluatorPort; потребляет SellableProvider | promotions.coupon.held, promotions.coupon.redeemed, promotions.coupon.released, promotions.budget.exhausted |
| stored-value | core | Contracts\\StoredValue | Реализует StoredValueTenderPort для payment | stored\_value.issued, stored\_value.held, stored\_value.captured, stored\_value.refunded |
| inventory | core | Contracts\\Inventory | Реализует StockCheckerPort, StockReservationPort | inventory.stock.reserved, inventory.stock.released, inventory.stock.committed, inventory.stock.adjusted |
| fulfillment | core | Contracts\\Fulfillment | Реализует ShippingQuotePort, PickupPointPort, ParcelPackerPort | fulfillment.shipment.created, fulfillment.shipment.shipped, fulfillment.shipment.delivered |
| payment | core | Contracts\\Payment | Реализует PaymentOrchestratorPort; определяет PaymentProvider, PaymentCustomizationRule | payment.session.requires\_action, payment.authorized, payment.captured, payment.refunded |
| cart | core | Contracts\\Carts | Потребляет SellableProvider, PriceResolverPort, PromotionEvaluatorPort, ShippingQuotePort, TaxEnginePort | cart.updated, cart.abandoned, cart.quote\_request.submitted, cart.staged\_quote.published |
| orders | core | Contracts\\Orders | Оркестрирует саги через WorkflowRunner; определяет OrderRoutingRule, OrderSplitterPort | orders.order.placed, orders.change.confirmed, orders.return.received, orders.order.canceled |
| subscriptions | core | Contracts\\Subscriptions | Подписывается на orders.order.placed с SellingPlan; триггерит рекуррентные саги | subscriptions.contract.created, subscriptions.billing\_cycle.due, subscriptions.contract.paused |
| bundle | Все доменные пакеты | Contracts\\Commerce | Автоматически связывает все порты core с реализациями доменных пакетов для standalone-магазина1 | Агрегирует события всех пакетов |
| testkit | core, orchestra/testbench, pestphp/pest | Contracts\\TestEnvironment | Предоставляет фейки всех портов core и контрактные трейты проверки адаптеров1 | Перехватывает и проверяет Outbox в памяти |

Детальная карта трансформации пакетов и разделения ответственности представлена в таблице ниже:

| Текущий пакет | Целевой пакет | Архитектурные изменения, переименования и границы ответственности |
| :---- | :---- | :---- |
| commerz/core | core | Консолидирует дубли Common\\Internal\\{Clock, Locks, Documents, Rows}2. Вводит TenantId, ActorRef, дробный Quantity, WorkflowRunner (саги с компенсацией), LinkRegistry (ModuleLink), CustomFieldRegistry и ConfigurableOperation4. |
| commerz/catalog | catalog | Добавляет наследование атрибутов вариант ← продукт, Collections (ручные и динамические), Brands, ProductRelations, слоты AssetAttachment, локализованный SlugRegistry с редиректами, порт CatalogSearchPort и пакетный upsertMany2. |
| commerz/pricing | pricing | Заменяет строковый sku в таблицах цен на SellableRef2. Добавляет агрегат Market, леджер истории цен за 30 дней (EU Omnibus), формульное наследование от parentPriceListId и черновые StagedPrice1. |
| *(внутри sales)* | tax *(новый)* | Выделяется из sales2. Управляет TaxClass, налоговыми зонами, B2B VAT Reverse Charge, каскадными ставками и портом внешних провайдеров (Avalara, TaxJar, Stripe Tax) — доступен и для витринного pricing, и для cart/orders2. |
| commerz/promotions | promotions | Вводит двухфазный холд/возврат лимитов купонов при отмене заказа, CampaignBudget (денежный потолок кампании), авто-добавление подарков (AutoGiftAction) и подсказки NearMissHint для роста среднего чека1. |
| *(отсутствует)* | stored-value *(новый)* | Реализует подарочные сертификаты (GiftCard) и балансовые кошельки клиентов (CustomerWallet / StoreCredit) с двойной записью в леджере и резервированием средств при чекауте2. |
| commerz/inventory | inventory | **Ликвидирует Fulfillments**, переименовывая финальное списание аллокации в StockCommit (commitAllocation)2. Переходит со строкового sku на иммутабельный InventoryItemId, комплекты InventoryKit и дробный Quantity1. |
| commerz/shipping | fulfillment *(переименован)* | Объединяет зоны и расчёт тарифов доставки, провайдеры ПВЗ/постаматов (PickupPointProvider), упаковку в несколько коробок (ParcelPacker), таможенные декларации и единственную в системе сущность физической отгрузки Shipment2. |
| *(внутри sales)* | payment *(новый)* | Выделяется из sales2. Вводит PaymentCollection и асинхронные PaymentSession (requires\_action для 3DS2, Multicapture, IncrementalAuthorization, сплит-оплату и правила PaymentCustomization)7. |
| commerz/sales | cart \+ orders *(разделён)* | cart отвечает за быстрые мутации корзины, QuotePipeline, трансформации CartTransform и B2B-переговоры (StagedQuote). orders (OMS) отвечает за конечный автомат заказа, маршрутизацию FulfillmentOrder, OrderChange (редактирование), Return (RMA), Exchange и DraftOrder3. |
| commerz/full | bundle \+ bridge-\* | bundle собирает стандартный магазин из всех пакетов2; отдельные адаптеры bridge-unbox, bridge-vaulter, bridge-azguard, bridge-chatom интегрируют коммерцию в вашу CMS без добавления CMS-зависимостей в ядро2. |
| commerz/testkit | testkit | Устраняет разнобой регистра неймспейса (Commerz\\TestKit в исходниках против Commerz\\Testkit в справочнике API)2, добавляет контрактные трейты для плагинов и генератор конкурентных гонок. |

### **1.3. Устранение терминологического дрейфа в публичных контрактах и DTO**

Между пакетами (а местами между документацией и кодом одного пакета) накопилась рассинхронизация базовых терминов и типов, которую необходимо вычистить единым дизайн-линтером перед фиксацией API:

| Проблемный узел в v0.1.0 | Где обнаружено расхождение | Единый целевой стандарт v1.0 |
| :---- | :---- | :---- |
| Ссылка на продаваемую сущность | В Core объявлен SellableRef(type, id)2; в Sales используется PurchasableRef / purchasable\_id2; в Pricing — string \$sellableId без type2; в Inventory и БД — сырой string \$sku2. | Повсеместно использовать только SellableRef(string \$type, string \$id) во всех DTO и составной ключ (sellable\_type, sellable\_id) в БД. |
| Представление денег | Класс Money упоминается в четырёх разных подпространствах (Core\\Types, Core\\Data, Core\\Security, Pricing\\Data), а в Pricing (PricePoint::\$unitPriceAmount) и Promotions (totalDiscountAmount) передаются сырые int2. | Единственный final readonly class Money в Core\\Money\\Money; полный запрет на передачу денежных сумм скалярным int в публичных DTO. |
| Смешение Cart и Quote | В Sales параллельно живут CartId/CartStatus и QuoteId/QuoteStatus, а таблицы именуются то commerz\_carts, то commerz\_quotes2. | Развести по модели commercetools1: Cart — активная сессионная корзина; QuoteResult — расчётный срез QuotePipeline; NegotiatedQuote — B2B коммерческое предложение с фиксированной ценой и сроком действия. |
| Наименование позиций и полей | Смешаны OrderLineId и OrderItemId2; у товаров и вариантов чередуются \$title и \$name2; вес передаётся как int \$weightGrams, decimal \$weight или Measure2. | Все позиции документов называть \*Line (CartLine, OrderLine, ShipmentLine, ReturnLine); заголовки — \$title; все физические величины — строго через Measure и Dimensions. |
| Дублирование статусов оплаты | На заказе и транзакции конфликтуют PaymentState и PaymentStatus с пересекающимися значениями2. | На заказе — агрегированный OrderPaymentState (unpaid, authorized, partially\_paid, paid, partially\_refunded, refunded); на платёжной сессии — PaymentSessionStatus. |

## **2\. Четыре архитектурных механизма расширяемости мирового класса**

Сейчас расширяемость commerz опирается на классический паттерн Ports & Adapters (PluginKind: SellableProvider, PricingRule, PromotionCondition, PromotionAction, PaymentGateway, ShippingCarrier, TaxCalculator, QuoteStage)1. Для замены шлюза доставки этого достаточно, но для коммерческой платформы и особенно для CMS-модуля этого мало: внешним расширениям необходимо безопасно связывать свои таблицы с товарами и заказами без нарушения модульной изоляции, добавлять типизированные поля с индексацией, откатывать многошаговые распределённые операции при частичных сбоях и автоматически описывать свои настройки для генерации UI в админке CMS3.

#### **Сводная матрица архитектурного бенчмарка по всем 14 критериям (Commerz v0.1 vs лидеры рынка vs целевое ядро v1.0)**

| Категория и критерий | Статус Commerz v0.1 | Эталон в OSS / SaaS (Medusa v2, Shopify, commercetools, Stripe, PHP OSS) | Диагностика реализации и ограничений в Commerz v0.1 | Целевая архитектурная спецификация для Target v1.0 |
| :---- | :---- | :---- | :---- | :---- |
| **1\. Ядро:** Изоляция домена от ORM и DTO | \[OK\] final readonly DTO | **Medusa v2:** Module SDK; **commercetools:** JSON Resources; **Lunar/Sylius:** жёсткая ORM-сцепка1 | Уже реализованы final readonly DTO, типизированные ID и маркер Undefined для различения null и пропуска поля при частичном обновлении1 | Сохранить полный запрет на утечку Eloquent наружу, унифицировать Money в Core и добавить CursorPage во все списочные контракты1 |
| **2\. Ядро:** Межмодульные связи (CMS / ERP) | \[PARTIAL\] Только строковые Ref | **Medusa v2:** defineLink (изолированные pivot-таблицы); **commercetools:** Custom Types4 | Есть CustomerRef и SellableRef, но нет штатного способа связать товар или заказ со статьёй, формой или сущностью хост-CMS с обратным поиском1 | Внедрить LinkRegistry (ModuleLink) по модели Medusa v2: таблица cmz\_entity\_links и батч-фасад CrossModuleQuery без межмодульных FOREIGN KEY4 |
| **3\. Ядро:** Пайплайны и Саги с компенсацией | \[PARTIAL\] Линейный пайплайн | **Medusa v2:** createWorkflow \+ createStep с compensate(); **Shopify:** детерминированные хуки3 | Пайплайны линейны: при сбое после внешнего вызова или мутации соседнего модуля нет автоматического каскадного отката предыдущих шагов1 | Построить WorkflowRunner поверх cmz\_operations: каждый шаг возвращает StepResult(output, compensationPayload) для гарантированного обратного отката8 |
| **4\. Ядро:** Событийная шина и Outbox | \[OK\] Транзакционный DatabaseOutbox | **Medusa v2:** Event Bus; **Shopify:** Webhooks; **commercetools:** Messages API; **Lunar:** sync in-memory1 | Доменные события атомарно пишутся в commerz\_outbox внутри БД-транзакции и вычитываются воркером1 | Добавить SKIP LOCKED для параллельных воркеров, исходящий/входящий шлюз Webhooks с HMAC-SHA256 и дедупликацию по event\_id1 |
| **5\. Ядро:** Блокировки БД и TestKit | \[PARTIAL\] Прямой FOR UPDATE | **Medusa v2:** Lock Module; **commercetools:** Optimistic version lock6 | Отличный ScenarioBuilder в TestKit, но прямой SELECT ... FOR UPDATE ломается или игнорируется на SQLite (SQLITE\_BUSY)1 | Вынести блокировки за DatabaseLockStrategy (FOR UPDATE на PG/MySQL \+ fallback на cmz\_locks в SQLite) и ввести version (OCC) на заказах1 |
| **6\. Каталог:** Атрибуты, наследование и поиск | \[PARTIAL\] Плоский SQL EAV-индекс | **commercetools:** ProductTypes \+ фасеты; **Shopify:** Metafields \+ Collections \+ SEO19 | Есть 10 AttributeType и FilterCompiler, но нет наследования в варианты, Media, SEO, брендов, коллекций, upsertMany и SearchPort1 | Ввести слот overridable\_variant, SmartCollection, AssetAttachment, cmz\_url\_routes с 301-редиректами, upsertMany и CatalogSearchPort с фасетами1 |
| **7\. Цены:** Прайс-листы, формулы и EU Omnibus | \[PARTIAL\] Статические тиры по sku | **Shopify:** Markets \+ Catalogs \+ FX; **commercetools:** StagedStandalonePrice9 | Цены в БД привязаны к строке sku, нет формульного расчёта от родительского прайса, нет Market и нет 30-дневной истории цен (EU Omnibus)1 | Привязать цены к SellableRef, ввести агрегат Market, формулы наценки/курса, StagedPrice и леджер lowestPriceLast30Days1 |
| **8\. Промо:** Стекинг, подарки и бюджеты | \[PARTIAL\] BestCombinationResolver | **Medusa v2:** CampaignBudget caps; **Shopify / commercetools:** авто-подарки и стек скидок10 | Оптимизатор комбинаций силён, но нет авто-добавления подарков в корзину, валютных бюджетов кампаний и возврата лимита купона при отмене заказа1 | Добавить двухфазный холд купонов (held \-\> redeemed / released), CampaignBudget (spend\_cap), AutoAddGiftLine и витринные подсказки NearMissHint1 |
| **9\. Товарные единицы:** Дробные меры и бандлы | \[GAP\] Строго int без бандлов | **Medusa v2:** Inventory Kits \+ дробные меры; **Shopify:** CartTransform (lineExpand / linesMerge)3 | Все количества в стоках, корзинах и заказах типизированы как int (невозможна продажа 1.5 кг или 2.5 м); нет сборных комплектов1 | Ввести scaled-decimal Quantity с политикой шага QuantityStepPolicy, комплекты InventoryKit на складе и стадию CartTransformStage в корзине3 |
| **10\. OMS:** Редактирование заказа (Order Edits) | \[GAP\] Заказ неизменяем | **Medusa v2:** двухфазный OrderChange; **commercetools:** OrderEdit (stagedActions \+ dryRun)6 | После создания заказ иммутабелен: нельзя добавить товар, поменять размер или пересчитать доставку с доплатой без полной отмены1 | Внедрить двухфазный OrderChange (stagedActions \-\> preview(dryRun) \-\> confirm()) с расчётом финансовой дельты к доплате или возврату6 |
| **11\. OMS:** Возвраты (RMA), обмены и брак | \[PARTIAL\] Только денежный Refund | **Medusa v2:** Return (RECEIVE\_RETURN\_ITEM vs DAMAGED) \+ OrderExchange \+ OrderClaim17 | В RefundItem есть точная поштучная разбивка налога и скидки, но физический возврат (RMA), отбраковка на складе и обмены отсутствуют1 | Связать математику RefundItem с процессом Return (инспекция склада: restock годного против writeOff брака), OrderExchange и OrderClaim1 |
| **12\. Платежи:** Multicapture и Хранимая стоимость | \[GAP\] Синхронный шлюз без кошельков | **Stripe:** PaymentIntents (multicapture, increment\_authorization); **Medusa:** PaymentCollection7 | Нет асинхронного статуса requires\_action (3DS2), частичных списаний под разные посылки, подарочных карт (GiftCard) и баланса (Wallet)1 | Выделить пакеты payment (PaymentCollection, isFinalCapture, incrementAuthorization) и stored-value (бухгалтерский леджер карт и кошельков)7 |
| **13\. Логистика:** Коллизия отгрузок, ПВЗ и Таможня | \[PARTIAL\] 3-сторонняя коллизия Fulfillment | **Shopify:** OrderRoutingLocationRule \+ PickupPoint; **Medusa v2:** единый Fulfillment \+ HS-коды12 | Fulfillments продублирован в Inventory, Shipping и Sales; нет ПВЗ (PUDO), упаковки в несколько коробок (Parcel\[\]) и таможенных полей1 | Оставить списание стока как StockCommit в inventory, консолидировать отгрузку в fulfillment (OrderRouter, PickupPoint, Parcel\[\], hsCode)12 |
| **14\. B2B и Рекуррентность:** Квоты, Черновики, Подписки | \[GAP\] Нет B2B и подписок | **Shopify:** SellingPlan \+ PaymentTerms; **commercetools:** QuoteRequest \-\> StagedQuote \-\> Quote13 | Отсутствуют менеджерские черновики DraftOrder, переговорные B2B-квоты, отсрочка платежа (Net 30 / депозиты) и подписки1 | Ввести DraftOrder, универсальный порт SellingPlan (подписки, предзаказы, депозиты), B2B-квоты StagedQuote, PaymentTerms и ApprovalFlow13 |

### **2.1. Декларативные межмодульные связи (ModuleLink по образцу Medusa v2)**

В commerz справедливо запрещены прямые внешние ключи и SQL JOIN между таблицами разных пакетов (ADR-002)2. Однако без специального механизма связывания любая попытка подключить внешний модуль (например, привязать к Product статью блога из CMS Unbox, вендора маркетплейса или карточку дизайнера) вынуждает разработчика складывать невалидируемые ID в JSON-поле metadata, по которому невозможно эффективно строить обратные выборки и каскадное удаление1. В **Medusa v2** эта задача решена через примитив defineLink, сохраняющий 100% изоляцию модулей4.  
В core необходимо ввести контракт LinkDefinition и реестр LinkRegistry: внешний модуль или CMS декларирует направленную связь между двумя точками LinkableResource с указанием кардинальности (oneToOne, oneToMany, manyToMany), политики каскадного удаления и опциональных дополнительных колонок связи4. Ядро сохраняет такие связи в изолированной инфраструктурной таблице связей cmz\_entity\_links (tenant\_id, link\_code, left\_id, right\_id, position, extra JSON) с прямым и обратным составными индексами. Фасад запросов CrossModuleQuery позволяет за два плоских батч-запроса без межмодульных JOIN обогащать ProductView или OrderView связанными объектами из CMS и фильтровать списки по наличию связи (whereLinked('cms.article', \$articleId)), даже если модуль каталога и CMS разнесены по разным базам данных4.

SQL  
\-- Инфраструктурная таблица межмодульных связей в пакете core (без внешних ключей к доменным таблицам)  
CREATE TABLE cmz\_entity\_links (  
    id CHAR(26) PRIMARY KEY,  
    tenant\_id VARCHAR(64) NOT NULL,  
    link\_code VARCHAR(128) NOT NULL, \-- например: 'catalog.product\_\_unbox.article'  
    left\_type VARCHAR(64) NOT NULL,  \-- 'catalog.product'  
    left\_id VARCHAR(64) NOT NULL,  
    right\_type VARCHAR(64) NOT NULL, \-- 'unbox.article'  
    right\_id VARCHAR(64) NOT NULL,  
    position INT NOT NULL DEFAULT 0,  
    extra JSON NULL,                 \-- кастомные атрибуты связи (например, required\_quantity или role)  
    created\_at TIMESTAMP NOT NULL,  
    UNIQUE KEY uq\_cmz\_entity\_links\_pair (tenant\_id, link\_code, left\_id, right\_id),  
    INDEX idx\_cmz\_links\_left (tenant\_id, link\_code, left\_id, position),  
    INDEX idx\_cmz\_links\_right (tenant\_id, link\_code, right\_id)  
);

PHP  
namespace Sellox\\Core\\Links\\Contracts;

use Sellox\\Core\\Links\\Data\\EntityLinkView;  
use Sellox\\Core\\Links\\Data\\LinkDefinition;

interface LinkRegistry  
{  
    public function define(LinkDefinition \$definition): void;

    /\*\* @param array\<string, mixed\> \$extra \*/  
    public function link(string \$linkCode, string \$leftId, string \$rightId, int \$position \= 0, array \$extra \= \[\]): EntityLinkView;

    public function unlink(string \$linkCode, string \$leftId, string \$rightId): void;

    /\*\* @param list\<string\> \$leftIds @return array\<string, list\<EntityLinkView\>\> \*/  
    public function getLinkedForMany(string \$linkCode, array \$leftIds): array;

    /\*\* @param list\<string\> \$rightIds @return array\<string, list\<EntityLinkView\>\> \*/  
    public function getInverseLinkedForMany(string \$linkCode, array \$rightIds): array;  
}

### **2.2. Схемо-валидируемые CustomFields (Metafields) и разделение публичных/приватных метаданных**

В текущей реализации часть DTO имеет неструктурированный массив metadata (в Catalog он присутствует не во всех представлениях — например, в базовой спецификации CategoryView поля метаданных нет)2. По образцу **Saleor** сырые метаданные на всех корневых агрегатах следует разделить на publicMetadata (безопасно отдаются во фронтенд-API витрины) и privateMetadata (доступны только бэкенду, вебхукам и ERP-интеграциям, например внутренний код маржинальности или ID поставщика)26. Поверх этого по гибридной модели **Shopify Metafields** (namespace \+ key \+ тип) и **Vendure CustomFields** стоит внедрить реестр CustomFieldRegistry3, позволяющий плагинам и CMS регистрировать типизированные поля на Order, Cart, CartLine, Customer, Category, StockLocation или Promotion (например, b2b.tax\_registration\_code или gift.engraving\_text). Если в определении поля указан флаг isFilterable: true, запись синхронно индексируется в обобщённую таблицу cmz\_custom\_field\_index (по аналогии с уже реализованным в каталоге commerz\_attribute\_index1), давая мгновенный поиск по кастомным полям заказов, клиентов и складов.

PHP  
namespace Sellox\\Core\\CustomFields\\Data;

use Sellox\\Core\\CustomFields\\Enums\\CustomFieldType;  
use Sellox\\Core\\CustomFields\\Enums\\FieldVisibility;

final readonly class CustomFieldDefinition  
{  
    /\*\*  
     \* @param array\<string, mixed\> \$validationRules  
     \* @param array\<string, string\> \$localizedLabel  
     \*/  
    public function \_\_construct(  
        public string \$targetAggregate, // 'order', 'cart\_line', 'category', 'stock\_location', 'customer'  
        public string \$namespace,       // изоляция плагина/приложения, например 'b2b' или 'unbox\_seo'  
        public string \$key,             // например 'vat\_number' или 'engraving\_text'  
        public CustomFieldType \$type,   // String, Int, Decimal, Bool, Date, Enum, Json, EntityRef  
        public FieldVisibility \$visibility \= FieldVisibility::Private, // PublicStorefront vs PrivateAdmin  
        public bool \$isFilterable \= false,  
        public bool \$isRequired \= false,  
        public array \$validationRules \= \[\],  
        public array \$localizedLabel \= \[\],  
    ) {}  
}

### **2.3. Оркестратор распределённых саг с функциями компенсации (WorkflowRunner по образцу Medusa v2)**

В DatabaseCheckout вызов внешнего платёжного шлюза уже вынесен за пределы БД-транзакции и строчных блокировок2. Но в реальном коммерческом процессе оформление или изменение заказа затрагивает цепочку из 4–6 независимых модулей и внешних API: резерв стока в Inventory, холд промокода в Promotions, списание подарочного сертификата в StoredValue, авторизация платежа у эквайера, запись Order и отправка заказа во внешнюю WMS/ERP1. Локальный DB::transaction() внутри одного пакета не способен откатить уже закоммиченные изменения в соседних изолированных модулях или внешних системах8.  
На базе уже имеющихся в core таблиц commerz\_operations и commerz\_idempotency\_keys2 следует построить полноценный движок саг **WorkflowRunner** по модели createWorkflow / createStep из **Medusa v2**1. Каждый шаг реализует контракт WorkflowStep с прямым методом invoke(\$input, $ctx):StepResult(возвращающимрезультатшагаиотдельно—compensationPayload,сериализуемыйвcommer{z}_{o}perations)иобратнымметодомcompensate($compensationPayload, \$ctx): void1. Для пакетных циклов внутри шага (например, синхронизации массива позиций с ERP) необходимо предусмотреть возврат StepResult::permanentFailure(\$reason, \$accumulatedPartialState), чтобы функция компенсации получила список элементов, успешно обработанных до момента падения цикла, и корректно откатила их1. Если сбой происходит на пятом шаге саги, WorkflowRunner детерминированно вызывает compensate() для шагов 4 → 3 → 2 → 1, используя DatabaseOutbox для гарантированного довыполнения сетевых компенсаций (таких как void платёжного холда) при временных обрывах связи. В ключевых системных сагах (PlaceOrderWorkflow, ConfirmOrderChangeWorkflow, UpsertProductWorkflow) нужно открыть типизированные **Workflow Hooks**, чтобы модули CMS могли встраивать свои шаги в общую транзакционную цепочку со своими откатами30.

PHP  
namespace Sellox\\Core\\Workflows\\Contracts;

use Sellox\\Core\\Workflows\\Data\\StepResult;  
use Sellox\\Core\\Workflows\\Data\\WorkflowContext;

/\*\*  
 \* @template TInput  
 \* @template TOutput  
 \* @template TCompensate  
 \*/  
interface WorkflowStep  
{  
    public function name(): string;

    /\*\*  
     \* Выполняет прямое действие шага. При частичном сбое внутри цикла возвращает  
     \* StepResult::permanentFailure(\$error, \$accumulatedStateForRollback).  
     \*  
     \* @param TInput \$input  
     \* @return StepResult\<TOutput, TCompensate\>  
     \*/  
    public function invoke(mixed \$input, WorkflowContext \$context): StepResult;

    /\*\*  
     \* Вызывается оркестратором в обратном порядке (N \-\> 1), если любой последующий шаг завершился ошибкой.  
     \*  
     \* @param TCompensate \$compensationPayload  
     \*/  
    public function compensate(mixed \$compensationPayload, WorkflowContext \$context): void;  
}

### **2.4. Самоописываемые плагины (ConfigurableOperation) и политики отказа перехватчиков (Shopify Functions)**

Сейчас расширения правил и типов (AttributeType::validateConfig(array \$config), PromotionRule, PromotionAction, драйверы доставки и платежей) принимают нетипизированный массив array \$config2. При встраивании в CMS это заставляет вручную верстать в админ-панели интерфейс настройки для каждого нового плагина. Внедрение паттерна **ConfigurableOperationDef из Vendure** решает эту проблему системно: каждый плагин возвращает декларативную схему своих аргументов ConfigSchema (код параметра, тип int \| decimal \| money \| percent \| boolean \| string \| localized\_string \| entity\_ref \| select, правила валидации, локализованные подписи и UI-хинт виджета)5. Админка вашей CMS запрашивает PluginRegistry::describeSchemas() и автоматически строит формы конфигурации для любых сторонних правил скидок, калькуляторов доставки или платёжных шлюзов.  
Одновременно для всех кастомных стадий пайплайна расчёта корзины, валидации чекаута и маршрутизации следует перенять у **Shopify Functions** явную декларацию политики сбоя FailurePolicy3. Если внешний или кастомный перехватчик выбрасывает исключение или превышает таймаут, ядро действует строго по его контракту: FailurePolicy::BlockOnFailure прерывает чекаут (обязательно для проверок возраста, санкционных/региональных ограничений, B2B кредитных лимитов или неразвёрнутых обязательных бандлов), тогда как FailurePolicy::IgnoreOnFailure логирует инцидент в диагностику и продолжает расчёт корзины без потери конверсии магазина (оптимально для рекомендательных модификаторов, сортировки способов доставки или косметических переименований)3.

PHP  
namespace Sellox\\Core\\Plugins\\Contracts;

use Sellox\\Core\\Plugins\\Data\\ConfigFieldSchema;  
use Sellox\\Core\\Plugins\\Enums\\FailurePolicy;

interface ConfigurableOperation  
{  
    public function operationCode(): string;

    /\*\*  
     \* Декларативная схема аргументов плагина для автогенерации форм настройки в админке хост-CMS.  
     \* @return list\<ConfigFieldSchema\>  
     \*/  
    public function configSchema(): array;

    /\*\* @param array\<string, mixed\> \$config \*/  
    public function validateConfig(array \$config): void;

    public function failurePolicy(): FailurePolicy; // BlockOnFailure или IgnoreOnFailure

    public function timeoutMilliseconds(): int; // по умолчанию 2000 мс для внешних перехватчиков  
}

## **3\. Схема БД, мультитенантность и мосты к экосистеме (Unbox, Vaulter, Azguard, Chatom)**

### **3.1. Ревизия схемы БД: TenantId, унификация первичных ключей, отказ от сырого sku и Quantity**

Сопоставление миграций всех семи пакетов с требованиями мультитенантной CMS выявило четыре структурных дефекта хранения данных, которые дешевле и чище всего исправить сейчас:

> 1. **Замена глобальных UNIQUE-индексов на тенант-скоупированные:** В приложениях вашей экосистемы (Unbox, Vaulter, Chatom, Azguard) изоляция данных строится вокруг workspace\_id2. В commerz сейчас только order\_number в commerz\_orders ограничен на уровне стора (store\_id, order\_number) и листинги — на уровне (store\_id, channel\_id, product\_id), тогда как sku, code, slug и коды купонов имеют **глобальный UNIQUE на всю таблицу** (commerz\_variants.sku, commerz\_products.sku, commerz\_categories.code и slug, commerz\_attributes.code, commerz\_options.code, commerz\_product\_types.code, commerz\_price\_lists.code, commerz\_promotions.code, commerz\_coupons.code, commerz\_inventory\_locations.code, commerz\_shipping\_zones.code, commerz\_shipping\_methods.code)2. В мульти-воркспейсной инсталляции CMS два разных проекта не смогут создать категорию со слогом new, купон WELCOME10 или артикул SKU-100. Введение в core контекста TenantContext (возвращающего константный 'default' в одиночном интернет-магазине и workspace\_id внутри CMS) и перенос tenant\_id на первую позицию всех уникальных и рабочих индексов (UNIQUE(tenant\_id, sku), UNIQUE(tenant\_id, code), UNIQUE(tenant\_id, store\_id, locale, slug)) полностью решает проблему без усложнения клиентского кода в standalone-режиме.  
> 2. **Ликвидация конфликта типов первичных ключей (BIGINT против ULID/UUID):** Между пакетами и документацией присутствует фундаментальный рассинхрон: все публичные DTO оперируют строковыми идентификаторами ULID/UUID2, таблицы commerz/catalog и части core создавались со строковыми id (string(64) / CHAR(26))2, но миграции inventory, pricing, promotions и shipping используют автоинкрементный \$table-\>id() (BIGINT UNSIGNED) и foreignId(...), поверх чего в репозиторий уже пришлось добавлять корректирующие миграции widen\_commerz\_\*\_integers.php2. Необходимо схлопнуть черновые миграции и стандартизировать единый первичный ключ во всех таблицах — **ULID** (CHAR(26)) или **UUIDv7** (CHAR(36) / BINARY(16)): как показывает практика вашего же пакета Chatom, лексикографически упорядоченные по времени идентификаторы дают отличную локальность вставки в B-Tree индексах, генерируются в PHP до обращения к БД и исключают утечку бизнес-объёмов через автоинкремент2.  
> 3. **Замена строкового sku в связях Pricing, Inventory и Shipping на иммутабельные ссылки:** Хотя архитектурная документация утверждает, что все модули ссылаются на продаваемые позиции через SellableRef(type, id)2, реальные миграции commerz\_prices, commerz\_tier\_prices, commerz\_inventory\_stock\_levels, commerz\_inventory\_stock\_movements, commerz\_inventory\_reservations и commerz\_shipment\_items привязаны к изменяемой колонке \$table-\>string('sku')2. Исправление опечатки в SKU варианта мгновенно разрушает связь товара с ценами и складскими остатками, а сущности CMS без артикула (консультации, подписки, доступы) приходится снабжать суррогатными SKU. Таблицы pricing должны индексироваться по (tenant\_id, sellable\_type, sellable\_id)2, а inventory (по модели **Medusa v2**) должен вести остатки на иммутабельном InventoryItem (inventory\_item\_id), связанном с SellableRef через таблицу маппинга комплектации11.  
> 4. **Переход от int к дробному Quantity и вынесение LockStrategy для SQLite:** Сейчас все количества в корзине, заказах, остатках и движениях склада жёстко типизированы как int, что блокирует продажу мерных, весовых и метражных товаров (ткани, кабель, весовые продукты, часы услуг)2. Введите в core иммутабельный Quantity (хранимый как BIGINT со скейлом 10^6 либо DECIMAL(18, 6\) с bcmath, как дробные единицы InventoryItem в Medusa v212) и объект правил кратности на Sellable: QuantityStepPolicy(min, max, step, unitCode). Одновременно вынесите стратегию пессимистичных блокировок в драйвер DatabaseLockStrategy: на MySQL 8+ и PostgreSQL он использует SELECT ... FOR UPDATE для остатков и FOR UPDATE SKIP LOCKED для параллельных воркеров DatabaseOutbox и очистки резервов, а на SQLite (где FOR UPDATE не поддерживается и вызывает обход блокировки либо SQLITE\_BUSY1) — автоматически переключается на именованный лок через commerz\_locks с контролируемым backoff.

#### **Ревизия существующих таблиц commerz\_\* и правила нормализации схемы БД для v1.0**

| Модуль | Существующие таблицы в v0.1.0 | Выявленные дефекты PK, UNIQUE и связей в v0.1.0 | Точная спецификация нормализации в v1.0 (префикс через CoreSchema::table()) |
| :---- | :---- | :---- | :---- |
| core | commerz\_stores, commerz\_channels, commerz\_currencies, commerz\_locales, commerz\_outbox, commerz\_idempotency\_keys, commerz\_operations, commerz\_locks1 | Смешаны BIGINT (stores, channels, outbox) и string PK (operations, locks); глобальный UNIQUE(code) на stores и channels1 | Все суррогатные PK перевести на CHAR(26) ULID; добавить tenant\_id VARCHAR(64) NOT NULL с индексами UNIQUE(tenant\_id, code); добавить таблицу cmz\_entity\_links и cmz\_custom\_field\_index |
| catalog | commerz\_attributes, commerz\_options, commerz\_product\_types, commerz\_products, commerz\_variants, commerz\_attribute\_index, commerz\_categories, commerz\_listings1 | Глобальные UNIQUE на sku, code, slug блокируют мультитенантность CMS; миграции содержат костыли widen\_commerz\_catalog\_integers1 | Удалить widen\_\*; ввести UNIQUE(tenant\_id, sku) и UNIQUE(tenant\_id, code); вынести slug из products/categories в таблицу маршрутов cmz\_url\_routes с поддержкой локалей и 301-редиректов |
| inventory | commerz\_inventory\_locations, commerz\_inventory\_stock\_levels, commerz\_inventory\_stock\_movements, commerz\_inventory\_reservations, commerz\_inventory\_fulfillments1 | Автоинкрементный BIGINT PK; привязка к изменяемой строке sku; количества строго int; дублирование fulfillments1 | Ввести cmz\_inventory\_items (id ULID, hs\_code, origin\_country) и cmz\_sellable\_inventory\_kits (sellable\_type, sellable\_id, inventory\_item\_id, required\_qty\_scaled); перевести количества на BIGINT (скейл 10^6); переименовать fulfillments в cmz\_stock\_commits11 |
| pricing | commerz\_price\_lists, commerz\_prices, commerz\_tier\_prices1 | Автоинкрементный BIGINT PK; глобальный UNIQUE(code) прайс-листа; привязка цен к строке sku вместо SellableRef1 | Перевести cmz\_prices и cmz\_tier\_prices на (tenant\_id, price\_list\_id, sellable\_type, sellable\_id, currency\_code, min\_qty\_scaled); добавить таблицы cmz\_markets, cmz\_staged\_prices и cmz\_price\_history (30-дневный минимум)1 |
| promotions | commerz\_promotions, commerz\_coupons, commerz\_coupon\_usages1 | Глобальный UNIQUE(code) на акциях и купонах; coupon\_usages не имеет статуса холда/возврата при отмене заказа1 | Индекс UNIQUE(tenant\_id, code); добавить cmz\_campaigns и cmz\_campaign\_budgets (spend\_cap\_minor, spent\_minor); в cmz\_coupon\_redemptions ввести статус held / redeemed / released и expires\_at1 |
| shipping \-\> fulfillment | commerz\_shipping\_zones, commerz\_carriers, commerz\_shipping\_rates, commerz\_shipments, commerz\_shipment\_items1 | Глобальный UNIQUE(code); одна отгрузка жестко равна одной коробке; в shipment\_items хранится сырой sku1 | Добавить cmz\_fulfillment\_orders (наряды складам от OrderRouter), cmz\_shipment\_parcels (несколько коробок и трек-номеров в одной отгрузке) и JSON/колонки pickup\_point16 |
| sales \-\> cart \+ payment \+ orders | commerz\_quotes, commerz\_orders, commerz\_payments, commerz\_refunds, commerz\_quote\_adjustments, commerz\_order\_adjustments1 | Смешаны корзины, платежи, налоги и заказы в одном модуле; нет таблиц для OrderChange, Return (RMA) и DraftOrder1 | Разделить схему: cmz\_carts \+ cmz\_staged\_quotes (cart); cmz\_payment\_collections \+ cmz\_payment\_sessions (payment); cmz\_orders \+ cmz\_order\_changes \+ cmz\_order\_change\_actions \+ cmz\_returns \+ cmz\_draft\_orders (orders)14 |

#### **Целевые PHP-контракты дробного количества Quantity и адаптера блокировок DatabaseLockStrategy**

PHP  
namespace Sellox\\Core\\Measures\\Data;

final readonly class Quantity  
{  
    public const int SCALE \= 6; // 1 единица \= 1\_000\_000 микро-единиц (например, 1.5 кг \= 1\_500\_000)

    public function \_\_construct(  
        public int \$scaledValue, // хранится в БД как BIGINT, исключая ошибки округления float  
        public string \$unitCode \= 'pcs',  
    ) {}

    public static function fromDecimal(string \$decimal, string \$unitCode \= 'pcs'): self;  
    public static function ofInt(int \$whole, string \$unitCode \= 'pcs'): self;  
    public function toDecimalString(): string;  
}

final readonly class QuantityStepPolicy  
{  
    public function \_\_construct(  
        public Quantity \$minQuantity,  
        public Quantity \$stepQuantity,  
        public ?Quantity \$maxQuantity \= null,  
    ) {}  
}

PHP  
namespace Sellox\\Core\\Locks\\Contracts;

use Closure;  
use Illuminate\\Database\\Query\\Builder;

interface DatabaseLockStrategy  
{  
    /\*\*  
     \* На PostgreSQL/MySQL добавляет SELECT ... FOR UPDATE (или FOR UPDATE NOWAIT / SKIP LOCKED).  
     \* На SQLite не добавляет неподдерживаемый SQL-клауз, полагаясь на внешний именованный лок в runInRowLock().  
     \*/  
    public function applyPessimisticWriteLock(Builder \$query, bool \$skipLocked \= false): Builder;

    /\*\*  
     \* Универсальная обёртка конкурентной мутации ресурса (работает идентично на PostgreSQL, MySQL и SQLite).  
     \* @template TReturn  
     \* @param list\<string\> \$resourceKeys отсортированный список ключей для предотвращения взаимных блокировок (deadlocks)  
     \* @param Closure(): TReturn \$operation  
     \* @return TReturn  
     \*/  
    public function executeAtomically(array \$resourceKeys, Closure \$operation, int \$ttlSeconds \= 10): mixed;  
}

### **3.2. Спецификация адаптеров-мостов для бесшовной работы внутри вашей CMS**

Чтобы сохранить нулевую связность ядра с хост-системой, но дать максимальную синергию внутри вашей платформы, интеграция оформляется четырьмя опциональными пакетами bridge-\*:

| Модуль экосистемы | Назначение в связке с коммерцией | Техническая реализация моста (bridge-\*) поверх портов ядра |
| :---- | :---- | :---- |
| **Unbox**2 | Хост-CMS, мультитенантные воркспейсы, кастомные сущности контента и форм | UnboxTenantResolver связывает TenantContext с активным workspace\_id2; UnboxSellableProvider позволяет продавать любые схемы Unbox (мероприятия, платные статьи, курсы, услуги) напрямую через корзину без дублирования в catalog2; конвертер DTO обеспечивает бесшовный маппинг final readonly объектов коммерции в spatie/laravel-data на слое UI/API CMS2. |
| **Vaulter**2 | Защищённое хранилище ассетов, превью, цифровая дистрибуция и шифрование | VaulterAssetAdapter разрешает AssetRef товаров/вариантов в подписанные CDN/превью-ссылки Vaulter2; VaulterDigitalFulfillmentListener по событию OrderPaid из Outbox выпускает временные криптографически подписанные ссылки с лимитом скачиваний для товаров DigitalKind2; VaulterPiiCipher прозрачно шифрует чувствительные поля адресов и налоговых идентификаторов в БД2. |
| **Azguard**2 | Аутентификация, RBAC-скоупы, политики доступа и неизменяемый аудит безопасности | В standalone-режиме доменные сервисы работают с ActorRef без проверки прав; с подключённым bridge-azguard декоратор команд проверяет гранулярные политики (например, commerce:price:override, commerce:order:refund, commerce:stock:adjust) и транслирует все ручные корректировки цен, балансов и статусов в центральный AuditLog системы Azguard2. |
| **Chatom**2 | Омниканальные мессенджеры, операторские чаты, маршрутизация и чат-боты | ChatomOutboxBridge пробрасывает доменные события (OrderPlaced, ShipmentDispatched, PaymentRequiresAction, CartAbandoned) в конвейер автоматизаций Chatom для уведомлений в WhatsApp/Telegram2; сервис ConversationalCheckout позволяет оператору или боту прямо внутри диалога с Contact собрать DraftOrder и отправить интерактивную ссылку на оплату с атрибуцией продаж на агента2. |

PHP  
namespace Sellox\\Core\\Host\\Contracts;

use DateTimeImmutable;  
use Sellox\\Core\\Data\\ActorRef;  
use Sellox\\Core\\Data\\TenantId;

interface TenantContext  
{  
    public function current(): TenantId; // 'default' в standalone-режиме или workspace\_id внутри Unbox  
    public function runAs(TenantId \$tenantId, callable \$callback): mixed;  
}

interface AssetResolverPort  
{  
    /\*\* @param list\<string\> \$assetIds @return array\<string, ResolvedAssetUrl\> \*/  
    public function resolvePublicUrls(array \$assetIds, ?string \$variantPreset \= null): array;  
}

interface DigitalDeliveryPort  
{  
    public function issueDownloadGrant(string \$assetId, string \$orderLineId, ?int \$maxDownloads, ?DateTimeImmutable \$expiresAt): string;  
}

interface AuthorizationGatePort  
{  
    public function authorize(ActorRef \$actor, string \$permissionScope, mixed \$resource \= null): void;  
}

## **4\. Расширение каталога, поиска, ценообразования и промо-механик**

### **4.1. Промышленный каталог (catalog) и фасетный поисковый контур**

В commerz/catalog уже заложена аккуратная система из 10 типов атрибутов (BooleanType, DateType, DecimalType, EnumType, IntegerType, LocalizedTextType, MeasureType, MultiEnumType, ReferenceType, TextType), строгая типизация через ProductTypes и компилятор SQL-фильтров FilterCompiler по плоской таблице commerz\_attribute\_index2. Для превращения его в полнофункциональный коммерческий PIM требуются пять расширений.  
Во-первых, необходимо реализовать **управляемое наследование атрибутов Product → Variant**, которое сейчас полностью отсутствует2. В таблицу слотов commerz\_product\_type\_attribute\_slots следует добавить третье значение уровня помимо product и variant2 — overridable\_variant: значение заполняется один раз на родительском продукте и автоматически наследуется всеми его вариантами, но может быть точечно переопределено на конкретном SKU (например, вес или материал отличается только у зимней модификации). При записи AttributeIndexer материализует итоговые эффективные значения варианта в индекс, сохраняя мгновенную скорость фильтрации без сложных COALESCE-джойнов при чтении.  
Во-вторых, следует закрыть пробел с **бандлами, комплектами и композитными товарами**2, объединив складской паттерн **Medusa v2 Inventory Kits** и корзинную механику **Shopify CartTransform**3. На уровне склада один вариант связывается с набором InventoryItem и коэффициентами расхода (required\_quantity, например 1 рама \+ 2 колеса для велосипеда, либо камера \+ объектив \+ сумка для фото-комплекта), а доступный к продаже остаток комплекта автоматически рассчитывается по наиболее дефицитному компоненту11. На уровне пайплайна корзины стадия CartTransformStage поддерживает операции lineExpand (разворачивает бандл в составные строки с индивидуальным учётом налоговых классов компонентов и покомпонентной сборкой на складе) и linesMerge (схлопывает отдельно добавленные акционные позиции в единый комбо-набор со специальной ценой)3.  
В-третьих, каталог нуждается в сущностях **мерчандайзинга, медиа-слотов и локализованной маршрутизации URL (SEO)**, которые сейчас отсутствуют во всех DTO2. Добавьте Collections двух видов: ручные списки с позиционированием и динамические умные подборки (SmartCollection), хранящие сериализованный фильтр AttributeFilter/VariantFilter для автоматического наполнения витринных разделов («Новинки со скидкой», «Лёгкие ноутбуки до 1.5 кг»)2. Добавьте направленные и симметричные товарные связи ProductRelations (upsell, cross\_sell, spare\_part, accessory, replacement) и сущность Brand2. Для медиа без втягивания бинарных файлов в БД введите легковесную привязку AssetAttachment (assetId, роль thumbnail \| gallery \| swatch \| video \| document, локализованный alt, position и опциональная привязка к optionValueId цвета, чтобы не клонировать галерею снимков на каждый размер)1. Для SEO выделите таблицу cmz\_url\_routes (tenant\_id, store\_id, locale, slug, entity\_type, entity\_id, is\_canonical, seo\_meta JSON): при изменении slug товара старый адрес автоматически становится записью 301-редиректа на новый is\_canonical \= true, защищая поисковый трафик магазина.  
В-четвёртых, в catalog нужно ввести **абстракцию поискового и фасетного движка (CatalogSearchPort) с cursor-пагинацией и пакетный upsertMany**2. Текущий ProductQuery выдаёт только список товаров с OFFSET/LIMIT (page, perPage до 100\)2, но реальная витрина интернет-магазина при каждом клике по фильтру обязана возвращать **фасетные счётчики (FacetDistribution)** — сколько товаров доступно для каждого бренда, размера, цвета и каков минимальный/максимальный порог цены в текущей выборке (с учётом правила дизъюнктивных фасетов: активный фильтр по цвету Красный не должен обнулять счётчики других цветов в этой же группе). Контракт CatalogSearchPort должен иметь встроенную SQL-реализацию поверх commerz\_attribute\_index (для малых и средних каталогов без внешней инфраструктуры), поддерживать быструю keyset/cursor-пагинацию для глубоких листингов и позволять прозрачно переключать чтение на адаптеры **Meilisearch, Typesense или Elasticsearch**, питающиеся батч-обновлениями из DatabaseOutbox. Параллельно методы Products::upsertMany() и Variants::upsertMany() обеспечат высокоскоростную пакетную синхронизацию десятков тысяч SKU из внешних ERP/PIM за секунды вместо поштучных транзакций1.

#### **Контракты фасетного поиска CatalogSearchPort и трансформации бандлов CartTransformStage**

PHP  
namespace Sellox\\Catalog\\Search\\Contracts;

use Sellox\\Catalog\\Search\\Data\\CatalogSearchQuery;  
use Sellox\\Catalog\\Search\\Data\\CatalogSearchResult;

interface CatalogSearchPort  
{  
    /\*\*  
     \* Выполняет фильтрацию товаров с расчётом дизъюнктивных фасетов (выбор 'Цвет \= Красный' сужает товары  
     \* и фасеты 'Размер'/'Бренд', но сохраняет реальные счётчики остальных значений внутри фасета 'Цвет')  
     \* и возвращает курсорную пагинацию (nextCursor / prevCursor) без деградации OFFSET на глубоких страницах.  
     \*/  
    public function search(CatalogSearchQuery \$query): CatalogSearchResult;

    /\*\* @param list\<string\> \$productIds \*/  
    public function indexProducts(array \$productIds): void;

    /\*\* @param list\<string\> \$productIds \*/  
    public function removeProducts(array \$productIds): void;  
}

PHP  
namespace Sellox\\Cart\\Transforms\\Contracts;

use Sellox\\Cart\\Data\\CartState;  
use Sellox\\Cart\\Transforms\\Data\\CartTransformOperation;

/\*\*  
 \* По образцу Shopify Cart Transform API: позволяет разворачивать сборные бандлы в компонентные строки  
 \* (LineExpand), сливать акционные наборы (LinesMerge) или переопределять презентацию/цену (LineUpdate).  
 \*/  
interface CartTransformRule  
{  
    public function code(): string;

    /\*\* @return list\<CartTransformOperation\> \*/  
    public function evaluate(CartState \$cart): array;

    public function blockOnFailure(): bool; // если true и бандл требует развёртки — блокирует чекаут при ошибке  
}

### **4.2. Рынки (Markets), история цен (EU Omnibus), формульные прайс-листы и независимый модуль tax**

В commerz/pricing уже работают приоритетные списки цен и количественные ступени TierPrice, но отсутствуют четыре критически важных для коммерческого продукта элемента2:

| Направление в pricing и tax | Текущее ограничение в v0.1.0 | Целевая реализация в v1.0 (по практикам commercetools, Medusa v2 и Shopify) |
| :---- | :---- | :---- |
| Региональный контекст (Market) | Параметры валюты, канала, стора и режима НДС передаются разрозненно; tax\_inclusive задаётся глобальным конфигом commerz.pricing.tax\_inclusive2. | Введение агрегата Market (countries\[\], defaultCurrency, allowedCurrencies\[\], isTaxInclusive на уровне рынка/валюты, как в Medusa v240, defaultPriceListId). Позволяет в одном магазине продавать в ЕС по ценам брутто в EUR и в США по ценам нетто в USD. |
| Наследование и формулы прайс-листов | В CreatePriceList объявлены \$parentPriceListId и \$strategy, но работает только статический fallback при отсутствии цены2. | Поддержка динамических формул от родительского листа или закупочной цены (base \* (1 ± margin%), конвертация валют по курсу с защитным спредом) плюс правила красивого округления (RoundingRule: до .99, .90, кратно 5 или 10\)2. |
| Черновые переоценки (StagedPrice) | Изменения цен вступают в силу сразу или требуют клонирования целых прайс-листов2. | По образцу **commercetools StagedStandalonePrice**9: подготовка отложенных правок цен с возможностью прогнать симуляцию пересечения с активными промоакциями до публикации на витрине. |
| Соответствие директиве EU Omnibus | Поле compare\_at\_amount хранится статично без проверки реальной истории цен за 30 дней2. | Журнал cmz\_price\_history и автоматическое вычисление lowestPriceLast30Days в ResolvedPriceView при любой уценке — обязательное юридическое требование для всех магазинов, торгующих на рынке ЕС2. |
| Выделение tax из sales | Отличный DefaultTaxCalculator (поддерживающий пропорциональный налог на доставку, каскадные юрисдикции, B2B Reverse Charge и копеечное распределение остатков) заперт внутри Commerz\\Sales2. | Вынос в автономный пакет tax с контрактом TaxEnginePort: позволяет не только считать чекаут, но и пересчитывать витринные брутто-цены каталога при смене покупателем страны доставки, а также подключать внешние провайдеры (Stripe Tax, Avalara, TaxJar)2. |

### **4.3. Развитие promotions и создание модуля хранимой стоимости (stored-value)**

В commerz/promotions уже реализован мощный комбинаторный резолвер BestCombinationResolver, выбирающий оптимальный стек неэксклюзивных акций для максимизации выгоды покупателя2. Чтобы закрыть выявленные финансовые и маркетинговые уязвимости, модуль нужно усилить в четырёх точках:

> 1. **Двухфазное удержание лимитов купонов и откат при отмене заказа:** В текущей версии Commerz\\Promotions фиксирует списание купона, но **не восстанавливает счётчик usage\_count и персональный лимит клиента при отмене заказа или неуспешной оплате**2. Переведите учёт использований на состояния held (при инициации чекаута с TTL) → redeemed (после подтверждения заказа/оплаты) → released (автоматический возврат попытки по событию OrderCancelled или при откате саги чекаута).  
> 2. **Контроль денежного бюджета кампаний (CampaignBudget из Medusa v2):** Ограничение акции только числом применений (usage\_limit) не защищает бизнес от кассового разрыва, если по процентному промокоду оформят несколько крупных оптовых корзин1. Добавьте сущность Campaign с атомарно контролируемым бюджетом CampaignBudget двух типов: лимит суммарной предоставленной скидки в валюте кампании (spend\_cap) и лимит акционных единиц товара (usage\_cap)10.  
> 3. **Автоматические подарки (AutoGiftAction) и витринные подсказки NearMissHint:** Сейчас действие BuyXGetY применяет 100% скидку только в том случае, если подарочный товар уже вручную добавлен покупателем в корзину2. Добавьте действие автоматического управления подарочной строкой AutoAddGiftLine (добавляет подарок с маркером системной строки при выполнении условия и удаляет его при уменьшении корзины), а для выборки из несколько возможных подарков — возврат в QuoteResult слота SelectableGiftOffer\[\] для отображения всплывающего виджета выбора подарка в корзине. Параллельно возвращайте массив NearMissHint («Добавьте товаров ещё на 12 EUR для получения бесплатной доставки») для повышения конверсии и среднего чека.  
> 4. **Выделенный пакет stored-value (GiftCard и CustomerWallet / StoreCredit):** Подарочные сертификаты и внутренние балансовые счета клиентов отсутствуют в ядре2, и их категорически нельзя эмулировать через купоны promotions: промо-купон снижает цену товаров и уменьшает налогооблагаемую базу НДС, тогда как многоцелевой подарочный сертификат и StoreCredit являются **платёжным средством (Tender)**, списываемым из итоговой суммы grandTotal *после* начисления всех налогов\! Пакет stored-value должен вести неизменяемый бухгалтерский леджер движений (issue, hold, capture, release, refund\_topup, expire), поддерживать частичное расходование номинала карты в нескольких заказах и интегрироваться в PaymentCollection как мгновенный внутренний способ оплаты и возврата средств.

SQL  
\-- Схема неизменяемого бухгалтерского леджера подарочных сертификатов и кошельков (пакет stored-value)  
CREATE TABLE cmz\_stored\_value\_accounts (  
    id CHAR(26) PRIMARY KEY,  
    tenant\_id VARCHAR(64) NOT NULL,  
    store\_id CHAR(26) NOT NULL,  
    account\_type VARCHAR(32) NOT NULL,    \-- 'gift\_card' или 'customer\_wallet'  
    code\_hash VARCHAR(64) NULL,           \-- SHA-256 хэш пин/кода подарочной карты (для gift\_card)  
    customer\_id VARCHAR(64) NULL,         \-- владелец кошелька (для customer\_wallet)  
    currency\_code CHAR(3) NOT NULL,  
    balance\_minor BIGINT NOT NULL DEFAULT 0,  
    held\_minor BIGINT NOT NULL DEFAULT 0, \-- доступно к списанию: (balance\_minor \- held\_minor) \>= 0  
    status VARCHAR(32) NOT NULL,          \-- 'active', 'disabled', 'expired'  
    expires\_at TIMESTAMP NULL,  
    version BIGINT NOT NULL DEFAULT 1,  
    created\_at TIMESTAMP NOT NULL,  
    UNIQUE KEY uq\_cmz\_sv\_code (tenant\_id, code\_hash),  
    UNIQUE KEY uq\_cmz\_sv\_wallet (tenant\_id, store\_id, customer\_id, currency\_code)  
);

CREATE TABLE cmz\_stored\_value\_ledger (  
    id CHAR(26) PRIMARY KEY,  
    tenant\_id VARCHAR(64) NOT NULL,  
    account\_id CHAR(26) NOT NULL,  
    entry\_type VARCHAR(32) NOT NULL,      \-- 'issue', 'hold', 'release', 'capture', 'refund\_credit', 'expire'  
    delta\_balance\_minor BIGINT NOT NULL,  
    delta\_held\_minor BIGINT NOT NULL,  
    balance\_after\_minor BIGINT NOT NULL,  
    reference\_type VARCHAR(64) NOT NULL,  \-- 'payment\_session', 'order\_refund', 'manual\_adjustment'  
    reference\_id VARCHAR(64) NOT NULL,  
    idempotency\_key VARCHAR(128) NOT NULL,  
    occurred\_at TIMESTAMP NOT NULL,  
    UNIQUE KEY uq\_cmz\_sv\_idempotency (tenant\_id, idempotency\_key),  
    INDEX idx\_cmz\_sv\_account\_time (account\_id, occurred\_at)  
);

## **5\. Современный эквайринг (payment) и пост-продажный OMS (orders: Order Edits, RMA, Exchanges, Draft Orders)**

В текущем Commerz\\Sales заказ после размещения фактически неизменяем: поддерживаются только полная отмена CancelOrder, удержание HoldOrder и денежный возврат Refund (где уже отлично сделана поштучная пропорциональная разбивка цен, налогов и скидок в RefundItem для избежания копеечных расхождений при частичном возврате)1. Однако реальный коммерческий магазин ежедневно сталкивается с асинхронным платёжным флоу, заменой позиций в уже оплаченном заказе, дозахватом средств при весовой сборке, физической приёмкой возвратов на склад с отбраковкой и оформлением черновых заказов менеджерами6.

#### **5.0.1. Полная спецификация 8-стадийного пайплайна расчёта корзины (QuotePipeline) и налоговых формул**

Расчёт корзины выполняется без открытия транзакционных локов чекаута (Read-Only I/O) путём последовательной передачи контекста QuotePipelineContext через 8 строго упорядоченных стадий1:

| № стадии | Класс стадии (QuoteStage) | Вызываемый порт / модуль | Обязанность стадии и мутация QuotePipelineContext | Политика сбоя (FailurePolicy) |
| :---- | :---- | :---- | :---- | :---- |
| 01 | ApplyCartTransforms | Внутренний реестр CartTransformRule | Развёртка бандлов (LineExpand), слияние комбо-наборов (LinesMerge) и применение SellingPlan к строкам3 | BlockOnFailure для обязательных бандлов (requiresComponents \= true), иначе IgnoreOnFailure3 |
| 02 | ResolveSellablesAndStock | SellableProvider \+ StockCheckerPort | Резолв метаданных товара (налоговый класс, вес, габариты, флаг isShippable) и проверка доступного остатка с учётом InventoryKit1 | Маркирует строку флагом isPurchasable \= false и добавляет предупреждение в QuoteResult::\$warnings1 |
| 03 | CalculateLineBasePrices | PriceResolverPort (pricing) | Расчёт базовых цен, ступеней TierPrice, модификаторов SellingPlan и lowestPriceLast30Days по активному Market1 | BlockOnFailure (строка без валидной цены в валюте корзины не может быть куплена)1 |
| 04 | ApplyPromotionsAndGifts | PromotionEvaluatorPort (promotions) | Прогон BestCombinationResolver, инъекция подарочных строк AutoAddGiftLine и пропорциональное распределение общей скидки заказа по строкам1 | BlockOnFailure для явно введённого клиентом промокода; IgnoreOnFailure для фоновых авто-правил |
| 05 | CalculateShippingOptions | ShippingQuotePort (fulfillment) | Расчёт доступных методов курьерской доставки, ПВЗ (PickupPoint) и самовывоза с учётом промо-правил бесплатной доставки1 | Возвращает пустой список тарифов и блокирует переход к оплате для isShippable \= true1 |
| 06 | CalculateTaxes | TaxEnginePort (tax) | Расчёт налогов на строки (после вычета скидок) и пропорционального налога на доставку с учётом режима isTaxInclusive и B2B VAT Reverse Charge1 | BlockOnFailure при финализации чекаута; fallback на локальную ставку при предварительном просмотре корзины |
| 07 | CompileGrandTotals | Внутренний агрегатор cart | Сведение subtotal, discountTotal, shippingTotal, taxTotal, grandTotal, а также dueNowAmount (при депозитах)1 | Строгая проверка инварианта неотрицательности сумм (INV-PRC-001)1 |
| 08 | BuildNearMissHints | PromotionEvaluatorPort | Генерация маркетинговых подсказок до ближайшего порога бесплатной доставки или подарка (NearMissHint\[\]) и сборка QuoteResult | IgnoreOnFailure (чисто информационный UX-сигнал для фронтенда витрины) |

Детерминированные математические правила налогового движка TaxEnginePort (исключающие копеечные расхождения между суммой строк и итогом заказа)1:

* **Распределение скидки уровня заказа по строкам:** до расчёта налогов общая скидка заказа распределяется пропорционально весу lineSubtotal каждой налогооблагаемой строки по алгоритму наибольшего остатка (Largest Remainder Method в целых минорных единицах), чтобы сумма построчных скидок в точности равнялась общей скидке заказа1.  
* **Режим цен без налога (isTaxInclusive \= false, например США / B2B):** налог начисляется поверх чистой суммы строки после скидки: ![][image1], а итог строки равен ![][image2]1.  
* **Режим цен с включённым НДС (isTaxInclusive \= true, например ЕС / РФ / Великобритания):** скидка сначала вычитается из брутто-суммы строки ![][image3], после чего из неё выделяется налоговая составляющая: ![][image4], а чистая база равна ![][image5]1.

#### **5.0.2. Двунаправленная спецификация саги чекаута PlaceOrderWorkflow (Штатный проход и Каскадный откат)**

Ниже зафиксированы оба операционных сценария саги оформления заказа, включая точные границы транзакций БД, отсутствие блокировок БД во время сетевых запросов к платёжному шлюзу и автоматический обратный откат резервов при сбое1:

# **ПРЯМОЙ ПОТОК (FORWARD 1 \-\> 5\) ГРАНИЦА ИЗОЛЯЦИИ ОБРАТНАЯ КОМПЕНСАЦИЯ (ROLLBACK 4 \-\> 1\)**

\[Шаг 1: ReserveInventoryStep\] \------\> \[Short Local DB Tx\] \<-- \[Откат \#3: releaseReservations(resIds)\]  
Мягкий TTL-резерв стока в inventory (SELECT FOR UPDATE Восстанавливает available остаток

* блокировка версии корзины только на строках SKU) по ID из compensationPayload шага 1

\| ^  
v \|  
\[Шаг 2: HoldPromotionsAndValueStep\] \------\> \[Short Local DB Tx\] \<-- \[Откат \#2: releaseHolds(holdIds)\]  
Холд купона \+ бюджета кампании \+ (Атомарная проверка Возвращает лимит купона, бюджет акции  
холд средств GiftCard / Wallet лимитов и баланса) и размораживает баланс кошелька/карты  
\| ^  
v \|  
\[Шаг 3: AuthorizePaymentsStep\] \------\> \[LOCK-FREE NET I/O\] \<-- \[Откат \#1: voidOrCancelSessions(payIds)\]  
Вызов внешнего PaymentProvider (ВНЕ транзакций БД Отменяет авторизованный холд в Stripe/  
(authorize / manual capture) и ВНЕ строчных локов\!) эквайринге (через ретраи Outbox при сбое)

# **\| ^ v \| \[Шаг 4: CommitOrderAndOutboxStep\] \------\> \[Cart Lock \+ DB Tx\] \--- (ПРИ ОШИБКЕ ЗАПИСИ БД / КОНФЛИКТЕ ВЕРСИИ: Запись Order, OrderLines, снапшота (Атомарная фиксация локальный DB Rollback шага 4 и старт налогов \+ запись OrderPlaced в Outbox заказа и событий) обратного каскада Откат \#1 \-\> \#2 \-\> \#3) \| v (только при успехе шага 4\) \[Шаг 5: AllocateAndRouteOrderStep\] \------\> \[Async Outbox Worker\] Конвертация резерва в Allocation (Фоновый воркер без и нарезка нарядов FulfillmentOrder задержки ответа клиенту)**

| Шаг саги PlaceOrderWorkflow | Сохраняемый compensationPayload в cmz\_operations | Поведение в Сценарии А (Успешный чекаут 1 \-\> 5\) | Поведение в Сценарии Б (Сбой на Шаге 4 после успешной авторизации карты) |
| :---- | :---- | :---- | :---- |
| 1\. ReserveInventoryStep | { reservationIds: string\[\], cartId: string } | Создаёт записи в cmz\_inventory\_reservations со статусом active и TTL 15 мин1 | Выполняется **Откат \#3**: переводит резервы в released и мгновенно возвращает единицы товара в доступный остаток на витрине1 |
| 2\. HoldPromotionsAndValueStep | { couponRedemptionId: ?string, budgetHoldId: ?string, storedValueHoldIds: string\[\] } | Фиксирует held на купоне, бюджете кампании и замораживает сумму held\_minor на подарочной карте/кошельке | Выполняется **Откат \#2**: переводит удержание купона в released, возвращает сумму в CampaignBudget и снимает held\_minor в леджере stored-value |
| 3\. AuthorizePaymentsStep | { paymentCollectionId: string, authorizedSessionIds: string\[\] } | Выполняет сетевой запрос к шлюзу без удержания соединений/локов БД1; при 3DS2 приостанавливает сагу в статусе awaiting\_action | Выполняется **Откат \#1**: вызывает PaymentProvider::cancelAuthorization() для всех авторизованных сессий (при сетевом таймауте ставит гарантированную задачу void в Outbox)1 |
| 4\. CommitOrderAndOutboxStep | { orderId: string } | Под коротким локом checkout:cart:{id} создаёт Order v1, закрывает корзину в completed и пишет orders.order.placed в DatabaseOutbox1 | Транзакция БД откатывается нативно (ROLLBACK), в Outbox ничего не попадает (нет фантомных уведомлений клиенту), запускается цепочка компенсации 3 \-\> 2 \-\> 11 |
| 5\. AllocateAndRouteOrderStep | { allocationIds: string\[\], fulfillmentOrderIds: string\[\] } | Переводит резервы в твёрдые Allocation, переводит купон в redeemed и создаёт FulfillmentOrder через OrderRouter1 | Шаг **пропускается** целиком, так как выполнение прервано на Шаге 4 |

#### **5.0.3. Полная матрица пост-продажных сценариев OrderChange и складской инспекции Return (RMA)**

Все изменения уже оформленного заказа проходят через двухфазный буфер OrderChange (синтез OrderChange из Medusa v2 и OrderEdit с dryRun-превью из commercetools): сначала формируется список stagedActions, затем метод preview() считает StagedOrder поверх актуальной версии Order без мутации БД, и только вызов confirm() запускает транзакционную сагу применения дельт6:  
\[Живой Order (v1)\] \---\> \[1. Буфер OrderChange (status: staged)\] \---\> \[2. Вызов preview() / dryRun: true\]  
Набор атомарных OrderChangeAction Сравнение excerptBefore и excerptAfter  
(не меняет живой Order и остатки\!) Расчёт amountDelta \= newTotal \- oldTotal  
\|  
\+----------------------------------------------------------------------------+  
v  
\[3. Вызов confirm() — Атомарное применение изменений к Order (v1 \-\> v2) по знаку финансовой дельты и типу RMA\]  
\|-- Ветка А (amountDelta \> 0, доплата): incrementAuthorization() на открытом холде ИЛИ новая PaymentCollection  
\|-- Ветка Б (amountDelta \< 0, переплата): частичный Refund на исходную карту ИЛИ начисление в CustomerWallet  
\+-- Ветка В (Складская приёмка RMA):  
\|-- Годный товар (RECEIVE\_RETURN\_ITEM) \---\> Restock (+quantity в доступный остаток локации)  
\+-- Бракованный товар (RECEIVE\_DAMAGED\_ITEM) \---\> Write-Off (0 в доступный сток, акт списания брака)

| Сценарий пост-продажного OMS | Тип changeType и коды действий OrderChangeAction | Финансовая дельта (amountDelta) | Действие в модуле payment / stored-value | Действие в модулях inventory и fulfillment |
| :---- | :---- | :---- | :---- | :---- |
| **1\. Редактирование заказа (+товар или замена на более дорогой)** | edit: ITEM\_ADD или ITEM\_UPDATE, SHIPPING\_RECALCULATE17 | amountDelta \> 0 (требуется доплата от покупателя) | Если первичный платёж ещё не списан (authorized) и шлюз поддерживает IncrementalAuthorization — вызов incrementAuthorization(); иначе создание новой PaymentSession / платёжной ссылки на сумму дельты и перевод заказа в awaiting\_payment15 | Резерв и аллокация добавленных единиц на складе; добавление позиций в открытый или новый FulfillmentOrder |
| **2\. Частичная отмена до отгрузки (-товар)** | edit: ITEM\_REMOVE или уменьшение quantity в ITEM\_UPDATE17 | amountDelta \< 0 (перерасчёт с учётом поштучных налогов и скидок) | Если платёж в authorized — списание уменьшенной суммы при отгрузке (capture меньшей суммы); если уже captured — автоматический частичный Refund или зачисление на CustomerWallet7 | Деаллокация отменённых единиц (deallocateStock) с мгновенным возвратом в available и пересчёт порога промоакций1 |
| **3\. Возврат кондиционного товара от клиента (RMA — Годный)** | return\_receive: RETURN\_ITEM \-\> приёмка складом RECEIVE\_RETURN\_ITEM17 | amountDelta \< 0 (поштучный расчёт через RefundItem \+/- удержание за обратную доставку)1 | После подтверждения приёмки складом: выплата Refund по исходной транзакции либо мгновенный выпуск CREDIT\_LINE\_ADD на CustomerWallet (Store Credit)17 | Создаётся обратная накладная возврата; при подтверждении RECEIVE\_RETURN\_ITEM выполняется **Restock** — физический и доступный остаток варианта увеличивается17 |
| **4\. Возврат бракованного или повреждённого товара (RMA — Брак)** | return\_receive: RETURN\_ITEM \-\> инспекция склада RECEIVE\_DAMAGED\_RETURN\_ITEM \+ WRITE\_OFF\_ITEM17 | amountDelta \< 0 (полная компенсация стоимости товара и доставки клиенту) | Полный частичный Refund на карту или CustomerWallet без удержания сервисных сборов | Выполняется **Write-Off**: факт приёмки брака фиксируется в журнале движений cmz\_inventory\_movements, но **продаваемый остаток available НЕ увеличивается** (защита от повторной продажи брака\!)17 |
| **5\. Обмен товара (OrderExchange) и Рекламация (OrderClaim)** | exchange / claim: связка RETURN\_ITEM (старый SKU) \+ ITEM\_ADD (новый SKU)14 | В exchange: newItems \+ returnShipping \- returnedItems (\> 0, \== 0 или \< 0); в claim: строго 0 | В exchange добирается или возвращается только чистая разница; в claim счёт покупателю равен 014 | Создаётся входящий Return на старый SKU и новый исходящий FulfillmentOrder на замену (с настройкой отправки замены сразу или только после сканирования возврата перевозчиком) |

### **5.1. Перепроектирование модуля payment: PaymentCollection, асинхронные сессии и гибкий эквайринг**

Текущий контракт PaymentGateway (authorize, capture, refund с булевым PaymentGatewayResult::\$successful) предполагает мгновенный синхронный ответ провайдера2. Для соответствия архитектуре **Stripe PaymentIntents**, **Adyen** и **Medusa v2 PaymentCollection** платёжный модуль необходимо перевести на трёхуровневую модель PaymentCollection → PaymentSession\[\] → PaymentTransaction\[\]7.  
Контейнер PaymentCollection привязывается к корзине, заказу, обмену или ревизии OrderChange и позволяет комбинировать в одной покупке несколько источников оплаты (например, частичное списание с GiftCard или CustomerWallet плюс доплата банковской картой)1. Каждая PaymentSession моделирует конечный автомат намерения у внешнего провайдера, включая состояние requires\_action с возвратом клиенту clientSecret, redirectUrl или QR-пейлоада для прохождения 3DS2/SCA-аутентификации и идемпотентной доводкой статуса через входящие вебхуки1.  
Сам интерфейс драйвера PaymentProvider следует снабдить декларацией поддерживаемых возможностей эквайринга (по спецификации **Stripe Flexible Acquiring**)7:

> 1. **Множественное частичное списание (Multicapture):** Передача флага isFinalCapture: bool в метод capture() позволяет при отгрузке заказа несколькими посылками с разных складов списывать средства частями строго в момент отправки каждой посылки, сохраняя остаток авторизованного холда активным (в Stripe поддерживается до 50 промежуточных списаний перед финальным final\_capture \= true)7.  
> 2. **Инкрементальная авторизация (IncrementalAuthorization) и Overcapture:** Позволяет увеличить сумму неподтверждённого холда (incrementAuthorization) при добавлении товара в заказ до отгрузки или списать сумму с небольшим превышением при фактическом взвешивании весового товара, с безопасным фоллбэком на прежнюю авторизованную сумму при отказе банка (card\_declined)15.  
> 3. **Детализация товарных позиций и налогов (PaymentLineItems):** Передача структуры позиций и налоговых сумм в запрос авторизации/списания решает сразу две коммерческие задачи — получение пониженных межбанковских тарифов L2/L3 в Stripe/Adyen44 и передачу точной номенклатуры во внешние адаптеры облачной фискализации чеков.  
> 4. **Пайплайн кастомизации платёжных методов (PaymentCustomization по образцу Shopify Functions):** Позволяет правилам магазина динамически скрывать, переименовывать и пересортировывать доступные способы оплаты на чекауте в зависимости от состава корзины, страны, суммы заказа, тегов клиента или наличия подписочного плана (например, скрывать постоплату для цифровых товаров или включать условие обязательной ручной проверки заказа reviewRequirement для крупных B2B-сумм)13.

Конечный автомат платёжной сессии (PaymentSessionStatus в модуле payment):  
\[pending\] \---\> (требуется 3DS2 / редирект / СБП QR) \---\> \[requires\_action\]  
\| \|  
\+----------------\> (успешный холд средств) \<-----------------+  
\|  
v  
\[authorized\] \<--- (incrementAuthorization: увеличение суммы холда до списания)  
/ \|  
(capture: isFinal=false) / \| \\ (cancelAuthorization / истечение окна холда)  
v \| v  
\[partially\_captured\] \| \[canceled\]  
\\ \|  
\\ \| (capture: isFinal=true или прямое списание charge)  
v v  
\[captured\] \---\> (частичный или полный возврат) \---\> \[partially\_refunded\] / \[refunded\]

PHP  
namespace Sellox\\Payment\\Contracts;

use Sellox\\Core\\Money\\Money;  
use Sellox\\Payment\\Data\\AuthorizeSessionRequest;  
use Sellox\\Payment\\Data\\CaptureSessionRequest;  
use Sellox\\Payment\\Data\\PaymentCapabilities;  
use Sellox\\Payment\\Data\\PaymentProviderResult;  
use Sellox\\Payment\\Data\\RefundSessionRequest;  
use Sellox\\Payment\\Data\\WebhookPayload;

interface PaymentProvider  
{  
    public function providerCode(): string;

    public function capabilities(): PaymentCapabilities; // флаги multicapture, incrementalAuth, overcapture, lineItemsL3

    public function initiateOrAuthorize(AuthorizeSessionRequest \$request): PaymentProviderResult;

    public function incrementAuthorization(string \$externalReference, Money \$newTotalAmount): PaymentProviderResult;

    public function capture(CaptureSessionRequest \$request): PaymentProviderResult; // содержит bool \$isFinalCapture

    public function cancelAuthorization(string \$externalReference): PaymentProviderResult;

    public function refund(RefundSessionRequest \$request): PaymentProviderResult;

    public function parseVerifiedWebhook(WebhookPayload \$payload): PaymentWebhookEvent;  
}

### **5.2. Двухфазное редактирование заказа (OrderChange), физические возвраты (RMA), обмены и черновики**

Для реализации полноценного пост-продажного OMS объедините архитектурные модели **OrderChange из Medusa v2** и **OrderEdit с режимом dryRun-превью из commercetools**6:

| Подсистема OMS (orders) | Механика работы и взаимодействие с inventory, payment и stored-value |
| :---- | :---- |
| **Редактирование заказа (OrderChange / OrderEdit)** | Создаётся черновой контейнер OrderChange со списком атомарных действий stagedActions (AddLine, ChangeLineQuantity, RemoveLine, OverrideUnitPrice, ChangeShippingMethod, ReplaceAdjustments)1. Вызов preview() выполняет dryRun-пересчёт налогов и промоакций на актуальной версии заказа без записи в БД, возвращая PreviewSuccess со снапшотами сумм excerptBeforeEdit / excerptAfterEdit и финансовой дельтой6. При вызове confirm() сага атомарно применяет дифф: при положительной дельте запрашивает incrementAuthorization либо генерирует новую PaymentCollection на доплату; при отрицательной — оформляет частичный Refund или зачисляет CreditLine на кошелёк клиента15. |
| **Физические возвраты (Return / RMA)** | Отделяет логистику возврата товара от выплаты денег1. Покупатель или оператор создаёт заявку Return с указанием ReturnReason, фото и обратной транспортной накладной в модуле fulfillment14. При фактической приёмке посылки на складе каждая единица проходит инспекцию с двумя раздельными действиями (как в Medusa v217): ReceiveReturnItem возвращает кондиционный товар в доступный остаток локации (restock), тогда как ReceiveDamagedReturnItem фиксирует приёмку и списывает брак (writeOff) **без увеличения продаваемого остатка на витрине**1\! После приёмки инициируется денежный Refund или мгновенный возврат на StoreCredit. |
| **Обмены (OrderExchange) и Рекламации (OrderClaim)** | Строятся как специализированные сценарии поверх OrderChange17: OrderExchange связывает входящий Return (возвращаемый размер/цвет) и новый исходящий FulfillmentOrder (высылаемая замена), рассчитывая к оплате или возврату только чистую разницу стоимости и доставки14. OrderClaim оформляет бесплатную досылку замены или недостающей детали при повреждении в пути с автоматическим списанием со склада и нулевым счётом для покупателя1. |
| **Черновики заказов (DraftOrder)** | Серверный заказ-черновик для телефонных продаж, B2B-менеджеров, POS-касс и чат-коммерции в Chatom1. Поддерживает произвольные некаталожные строки (CustomLineItem), индивидуальные скидки, ручное переопределение цен/доставки, проверку готовности к финализации и конвертацию либо в ссылку на онлайн-чекаут для клиента, либо напрямую в размещённый Order с отсрочкой платежа13. |

PHP  
namespace Sellox\\Orders\\Changes\\Contracts;

use Sellox\\Orders\\Changes\\Data\\AddOrderChangeAction;  
use Sellox\\Orders\\Changes\\Data\\CreateOrderChange;  
use Sellox\\Orders\\Changes\\Data\\OrderChangeId;  
use Sellox\\Orders\\Changes\\Data\\OrderChangePreview;  
use Sellox\\Orders\\Changes\\Data\\OrderChangeView;  
use Sellox\\Orders\\Data\\OrderView;

interface OrderChanges  
{  
    public function create(CreateOrderChange \$command): OrderChangeView;

    public function addAction(OrderChangeId \$changeId, AddOrderChangeAction \$action): OrderChangeView;

    /\*\*  
     \* Выполняет dryRun-расчёт поверх актуальной версии Order без сохранения изменений в таблицы заказов.  
     \* Возвращает excerptBefore, excerptAfter, налоговый перерасчёт и точную сумму amountDelta.  
     \*/  
    public function preview(OrderChangeId \$changeId): OrderChangePreview;

    /\*\*  
     \* Запускает ConfirmOrderChangeWorkflow: проверяет optimistic lock (expectedOrderVersion),  
     \* применяет складские и финансовые диффы и переводит Order на следующую ревизию.  
     \*/  
    public function confirm(OrderChangeId \$changeId, int \$expectedOrderVersion): OrderView;

    public function cancel(OrderChangeId \$changeId): void;  
}

## **6\. Умная логистика (fulfillment), ПВЗ (PUDO), подписки (SellingPlan) и B2B-коммерция**

### **6.1. Маршрутизация заказов (OrderRouter), ПВЗ/постаматы, мульти-посылки и таможня**

В текущей реализации Commerz\\Inventory распределяет сток последовательно по фиксированному числу priority локации, а Commerz\\Shipping не поддерживает пункты выдачи заказов (ПВЗ/постаматы), разбиение отгрузки на несколько грузовых мест, частичные адреса экспресс-чекаутов и таможенные декларации1.  
Для вывода логистического контура на уровень **Shopify Plus** между заказом и физическим отправлением Shipment следует ввести промежуточный агрегат **FulfillmentOrder** (наряд конкретному складу на сборку выделенной группы строк заказа) и конвейер правил маршрутизации **OrderRouter** (по образцу Shopify OrderRoutingLocationRule и FulfillmentConstraints)16. Конвейер ранжирует доступные склады по комбинации подключаемых стратегий: минимизация сплит-отгрузок (приоритет локации, способной отгрузить корзину целиком без дробления на две посылки), географическая близость к адресу покупателя, привязка к рынку, учёт ограничений совместимости товаров (FulfillmentConstraints — например, товары холодовой цепи или опасные грузы отгружаются только со спецсклада) и балансировка дневной пропускной способности розничных точек1.  
Внутри самого модуля fulfillment необходимо закрыть четыре прикладных пробела:

> 1. **ПВЗ, постаматы (PUDO) и самовывоз из магазина (BOPIS):** Расширьте модель доставки режимами Shipping, PickupPoint и LocalPickup (как генераторы опций доставки в Shopify Functions25). Введите контракт PickupPointProvider для получения списка терминалов/ПВЗ в радиусе координат или города с проверкой габаритных и весовых ограничений ячейки постамата, а выбранную точку сохраняйте в типизированном объекте PickupPointDestination отдельно от платёжного адреса покупателя. Учитывайте также сценарии **сплит-доставки в одной корзине** (часть товаров забирается самовывозом сегодня, а крупногабаритная позиция едет курьером со склада)25.  
> 2. **Устойчивость к частичным адресам (Accelerated Checkouts):** При расчёте стоимости доставки на карточке товара или до подтверждения Apple Pay / Google Pay известны только страна, город и префикс почтового индекса без улицы и телефона41. Сопоставление ShippingZone и расчёт тарифов RateCalculator должны поддерживать префиксный матчинг индексов и не отфильтровывать тарифы, если опциональные поля адреса равны null1.  
> 3. **Алгоритм упаковки посылок (ParcelPacker):** Замените жёсткую связку «одна отгрузка \= один трек-номер»2 на структуру Shipment → Parcel\[\], где подключаемая стратегия ParcelPacker распределяет единицы товаров по коробкам с расчётом физического и объёмного веса для точного тарифицирования у перевозчиков2.  
> 4. **Международная торговля и таможня:** Добавьте в InventoryItem / Variant коды ТН ВЭД (hsCode), код страны происхождения (originCountryCode), материал и таможенное описание (как в физических и таможенных атрибутах Medusa v212), чтобы адаптеры международных перевозчиков (DHL, FedEx, UPS) могли автоматически формировать коммерческие инвойсы и декларации1.

Текстовая схема конвейера логистической маршрутизации и отгрузки (orders \-\> inventory \-\> fulfillment):  
\[OrderLines с флагом isShippable \= true\]  
\|  
v  
\[Шаг 1: FulfillmentConstraints (ограничения совместимости)\]  
Фильтрация допустимых локаций по свойствам товара (холодовая цепь, крупногабарит, must-ship-together)  
\|  
v  
\[Шаг 2: OrderRouter (ранжирование складов LocationRankingRule\[\])\]

> 1. Минимизация сплит-посылок \-\> 2\) Гео-близость к индексу клиента \-\> 3\) Загрузка склада \-\> 4\) Приоритет  
>    \|  
>    v  
>    \[Шаг 3: Генерация нарядов FulfillmentOrder\[\] (по одному на выбранную локацию и метод: Курьер / ПВЗ / BOPIS)\]  
>    \|  
>    v  
>    \[Шаг 4: ParcelPacker (распределение позиций наряда по коробкам Parcel\[\] с физическим и объёмным весом)\]  
>    \|  
>    v  
>    \[Шаг 5: Создание Shipment в модуле fulfillment \+ получение трек-номеров и этикеток от CarrierProvider\]  
>    \|  
>    v  
>    \[Шаг 6: Вызов Inventory::commitAllocation() (StockCommit) \+ триггер частичного списания Multicapture в payment\]

### **6.2. Универсальная абстракция SellingPlan (подписки, предзаказы, депозиты) и B2B-модуль**

Вместо создания отдельного дублирующего пайплайна под рекуррентные платежи и предзаказы внедрите в core и cart элегантную архитектурную модель **Shopify SellingPlan**3. Любой Sellable может предлагаться как в разовую покупку, так и в рамках одного или нескольких планов продажи SellingPlan, каждый из которых состоит из трёх декларативных политик: PricingPolicy (например, скидка 10% за подписку или спеццена раннего предзаказа), BillingPolicy (Recurring с интервалом списания, Deposit с предоплатой 20% сейчас и 80% перед отгрузкой, либо Deferred с отложенным списанием после периода примерки *Try Before You Buy*) и DeliveryPolicy (расписание регулярных отгрузок или плановая дата поступления партии предзаказа). Стандартный QuotePipeline сразу считает сумму первого платежа (dueNowAmount) и будущих обязательств (remainingBalanceAmount), требуя сохранения платёжного метода (setupFutureUsage), а отдельный пакет subscriptions подписывается на OrderPlaced в Outbox и управляет расписанием повторных заказов\!  
Для корпоративного **B2B-сегмента и мульти-вендорных проектов** (часто создаваемых именно на базе корпоративных CMS) в архитектуру стоит заложить четыре чистых расширения:

> 1. **Трёхэтапные B2B-квоты (по образцу commercetools QuoteRequest → StagedQuote → Quote):** Покупатель отправляет собранную корзину как запрос коммерческого предложения (QuoteRequest), независимый от дальнейших изменений его корзины; продавец принимает запрос в работу как StagedQuote, корректирует объёмы и назначает прямые скидки; после публикации финального NegotiatedQuote покупатель может сконвертировать его в заказ по зафиксированным ценам до истечения срока действия либо отправить запрос на пересмотр условий (Renegotiate)18.  
> 2. **Платёжные условия и графики (PaymentTerms):** Поддержка отсрочки платежа по счёту (Net15, Net30, Net60, фиксированная дата погашения или график с депозитом)1 с проверкой доступного кредитного лимита компании в валидаторе чекаута (и автоматической блокировкой совмещения PaymentTerms с подписочными SellingPlan, как в правилах валидации Shopify).  
> 3. **Иерархия бизнес-юнитов и цепочки согласования (BusinessUnit \+ ApprovalFlow):** По аналогии с B2B-контуром commercetools19 позволяет привязывать сотрудников-закупщиков (Associate) к подразделениям компании с собственными адресами, налоговыми номерами, персональными прайс-листами и правилами согласования заказов руководителем при превышении бюджетного порога.  
> 4. **Точка расширения под маркетплейс (SellerRef \+ OrderSplitterPort):** Опциональное поле sellerId на листинге/предложении и строка стратегии разделения заказа (аналог OrderSellerStrategy в Vendure5) позволят при необходимости превратить магазин на вашей CMS в мульти-вендорный маркетплейс, разбивающий мастер-чекаут покупателя на суб-заказы продавцов со сплитованием выплат.

PHP  
namespace Sellox\\Core\\Sellable\\Data;

/\*\*  
 \* Универсальная модель плана продажи (подписки, предзаказы с депозитом, Try Before You Buy).  
 \*/  
final readonly class SellingPlan  
{  
    public function \_\_construct(  
        public string \$id,  
        public string \$code,  
        public string \$name,  
        public SellingPlanPricingPolicy \$pricingPolicy,   // FixedAmountOff, PercentageOff, FixedPrice  
        public SellingPlanBillingPolicy \$billingPolicy,   // Recurring(interval), Deposit(percent, dueAt), Deferred(days)  
        public SellingPlanDeliveryPolicy \$deliveryPolicy, // RecurringInterval, FixedAnchorDate, PreorderReleaseDate  
        public bool \$requiresSavedPaymentMethod \= true,  
    ) {}  
}

Жизненный цикл B2B коммерческого предложения (по образцу commercetools Quotes):  
\[Покупатель: Cart\]  
\| (submitQuoteRequest: снимок корзины изолируется от дальнейших правок Cart)  
v  
\[QuoteRequest: status \= submitted\] \---\> (отклонено продавцом / отменено покупателем) \---\> \[rejected / canceled\]  
\|  
\| (продавец берёт в работу: createStagedQuote)  
v  
\[StagedQuote: status \= in\_progress\] \<---+ (запрос пересмотра условий покупателем: requestRenegotiation)  
Продавец меняет количества, сроки, \|  
PaymentTerms (Net 30\) и даёт прямые \|  
скидки на строки или весь документ \|  
\| \|  
\| (публикация оферты клиенту: publishQuote с фиксацией validUntil)  
v \|  
\[NegotiatedQuote: status \= pending\] \----+  
\|  
\| (акцепт покупателем \+ прохождение корпоративного ApprovalFlow при превышении лимита)  
v  
\[Order: создан по зафиксированным ценам NegotiatedQuote\]

## **7\. Высоконагруженные проекции, вебхуки, TestKit и мастер-план для AI-агента**

### **7.1. Операционная производительность, безопасность данных и контрактное тестирование**

Для безупречной работы под высокой витринной нагрузкой и соответствия требованиям коммерческой эксплуатации инфраструктурный слой пакета следует дополнить четырьмя компонентами.  
Во-первых, введите **денормализованную витринную проекцию StorefrontProjection** (по образцу ProductProjection в commercetools19). Сборка карточки товара на лету из таблиц продуктов, вариантов, EAV-значений, листингов канала, прайс-листов, тиров, 30-дневного минимума цены и агрегированного наличия на складах создаёт лишнюю нагрузку при высоком RPS витрины. Легковесный проектор, слушающий события каталога, цен и остатков из DatabaseOutbox, поддерживает готовую таблицу cmz\_storefront\_products (tenant\_id, market\_id, locale, product\_id, собранный JSON-снапшот карточки с унаследованными атрибутами, витринным диапазоном цен и булевым статусом наличия), превращая рендеринг карточки товара и листинга в одиночное чтение по составному ключу без многотабличных JOIN.  
Во-вторых, поверх DatabaseOutbox и DatabaseIdempotency2 оформите двусторонний **шлюз вебхуков**: исходящий диспетчер WebhookSubscriptions (фильтрация по типам доменных событий, подпись HMAC-SHA256, экспоненциальные ретраи, автоматическое отключение мёртвых эндпоинтов и ручной реплей из админки) и входящий InboundWebhookGateway для безопасного приёма асинхронных уведомлений от платёжных провайдеров и курьерских служб с верификацией криптоподписи и защитой от повторной обработки по external\_event\_id2.  
В-третьих, добавьте в OMS встроенную **процедуру анонимизации персональных данных (GDPR / право на забвение)**41: поскольку финансовые документы (orders, payments, refunds) нельзя удалять из БД без нарушения бухгалтерских инвариантов и сверки sales-order-totals2, команда Orders::erasePersonalData(CustomerRef \$customer) должна маскировать ФИО, email, телефоны и точные уличные адреса в архивных заказах, сохраняя страну, регион, суммы, налоги и товарный состав.  
В-четвёртых, превратите **testkit в инструмент сертификации сторонних плагинов**2. Помимо существующего ScenarioBuilder и DefaultTestEnvironment, добавьте переиспользуемые наборы контрактных Pest/PHPUnit-трейтов (AssertsPaymentProviderCompliance, AssertsSearchEngineCompliance, AssertsCarrierCompliance, AssertsSellableProviderCompliance), которые разработчик любого кастомного адаптера подключает в свой тест-сьют для автоматической проверки идемпотентности, граничных условий округления и обработки ошибок. Дополните testkit многопроцессным раннером конкурентных гонок на реальном PostgreSQL/MySQL для автоматической верификации отсутствия oversell-эффекта на последнем остатке товара и последнем купоне.

### **7.2. Формальные конверты событий/ошибок и полный реестр инвариантов v1.0 для AI-агента**

Чтобы AI-агент соблюдал единый машинный протокол во всех новых и перерабатываемых модулях, ниже зафиксированы канонические JSON-схемы ошибок и событий DatabaseOutbox (расширяющие существующие спецификации error-codes.md и events.md полем tenant\_id и трассировкой саг), а также полный реестр архитектурных инвариантов1:

JSON  
{  
  "\_comment\_error\_envelope": "Стандарт сериализации исключений SelloxException (HTTP 422 / 404 / 409 / 500)",  
  "error": {  
    "code": "ORDERS\_ORDER\_CHANGE\_VERSION\_CONFLICT",  
    "http\_status": 409,  
    "message": "Order 01J9X... was modified concurrently (expected version 2, actual version 3).",  
    "target": "expected\_order\_version",  
    "details": \[  
      {  
        "code": "OPTIMISTIC\_LOCK\_MISMATCH",  
        "message": "Re-run OrderChanges::preview() before confirming."  
      }  
    \]  
  }  
}

JSON  
{  
  "\_comment\_outbox\_envelope": "Стандарт сериализации доменного события в таблице cmz\_outbox (только примитивы, деньги в minor units)",  
  "event\_id": "01J9Z8K4M2ABCDEF1234567890",  
  "event\_type": "orders.change.confirmed",  
  "schema\_version": 1,  
  "occurred\_at": "2026-11-22T18:30:00.000000Z",  
  "tenant\_id": "ws\_01J9A00000WORKSPACE0000001",  
  "store\_id": "01J9A11111STORE00000000001",  
  "channel\_id": "01J9A22222CHANNEL000000001",  
  "actor\_type": "admin\_user",  
  "actor\_id": "usr\_9481",  
  "workflow\_execution\_id": "01J9Z8K400WORKFLOW00000001",  
  "correlation\_id": "01J9Z8K300CORRELAT00000001",  
  "causation\_id": "01J9Z8K399CAUSATIO00000001",  
  "aggregate\_type": "order",  
  "aggregate\_id": "01J9Z70000ORDER00000000001",  
  "payload": {  
    "order\_change\_id": "01J9Z80000CHANGE0000000001",  
    "version\_from": 1,  
    "version\_to": 2,  
    "currency\_code": "EUR",  
    "amount\_delta\_minor": 2500  
  }  
}

| Код инварианта | Домен | Строгое правило (проверяется статическим анализом и тестами testkit) |
| :---- | :---- | :---- |
| INV-CORE-001 | core | Все метки времени получаются исключительно через порт Clock, прямые вызовы now() и Carbon::now() запрещены1 |
| INV-CORE-002 | core | Любые денежные суммы хранятся и передаются как целые минорные единицы внутри Core\\Money\\Money с 3-буквенным кодом ISO-4217; float запрещён1 |
| INV-CORE-003 | core | Все количества продаваемых и складских единиц выражаются через Quantity (scaled integer 10^6) с явным unitCode1 |
| INV-CORE-004 | core | Любой уникальный бизнес-индекс (sku, code, slug, coupon, order\_number, idempotency\_key) обязан иметь tenant\_id первой колонкой1 |
| INV-CORE-005 | core | Запись доменного события в DatabaseOutbox выполняется строго внутри той же локальной БД-транзакции, что и мутация состояния агрегата1 |
| INV-CORE-006 | core | Между таблицами разных доменных пакетов запрещены SQL JOIN и FOREIGN KEY; межмодульные связи строятся только через SellableRef и LinkRegistry1 |
| INV-CAT-001 | catalog | Ни один вариант не может дублировать комбинацию optionValueIds другого активного варианта внутри одного родительского продукта1 |
| INV-CAT-002 | catalog | При смене канонического slug в cmz\_url\_routes прежний маршрут атомарно сохраняется со статусом is\_canonical \= false для 301-редиректа |
| INV-PRC-001 | pricing / tax | Итоговая цена единицы, строки и заказа не может быть отрицательной (\>= 0); сумма построчных налогов и скидок обязана до копейки сходиться с итогом документа1 |
| INV-PRC-002 | pricing | Каждое изменение опубликованной цены записывает точку в cmz\_price\_history для точного расчёта 30-дневного минимума EU Omnibus1 |
| INV-PRM-001 | promotions | Сумма активных удержаний (held) и списаний (redeemed) не может превышать ни usage\_limit купона, ни валютный spend\_cap в CampaignBudget1 |
| INV-SV-001 | stored-value | В леджере карт и кошельков всегда выполняется balance\_minor \- held\_minor \>= 0; прямое изменение баланса без записи в cmz\_stored\_value\_ledger запрещено |
| INV-INV-001 | inventory | Физический остаток on\_hand \>= 0 и доступный остаток on\_hand \- reserved \- allocated \>= 0 (кроме явно включённого allow\_backorder на локации/товаре)1 |
| INV-INV-002 | inventory | При приёмке брака по RMA (RECEIVE\_DAMAGED\_RETURN\_ITEM) остаток списывается через writeOff и никогда не увеличивает доступный к продаже сток17 |
| INV-PAY-001 | payment | Любой сетевой вызов к внешнему PaymentProvider обязан выполняться вне открытых транзакций БД и вне пессимистичных блокировок строк1 |
| INV-OMS-001 | orders | Размещённый Order не мутируется напрямую: любые финансовые и товарные изменения после чекаута проводятся только через подтверждённый OrderChange с увеличением version6 |

### **7.3. Последовательный план декомпозиции работ для вашего AI-агента**

Поскольку кодовая база модульная и ещё не связана обязательствами обратной совместимости, оптимально вести разработку с AI-агентом в пять строго изолированных фаз, фиксируя после каждой фазы 100% прохождение статического анализа и сценариев testkit:

> 1. **Фаза 1 — Ребрендинг, ядро core и нормализация фундамента БД:** зафиксировать финальное имя вендора/неймспейса, конфигурируемый префикс таблиц CoreSchema::table() и выровнять регистр TestKit; перенести дубли Common\\Internal\\{Clock, Locks, Documents, Rows} в core; перевести все первичные ключи всех пакетов на единый формат ULID/UUIDv7 и удалить старые патч-миграции widen\_\*; внедрить TenantId/TenantContext на первую позицию всех уникальных индексов; унифицировать Money, ввести дробный Quantity с QuantityStepPolicy, адаптер DatabaseLockStrategy (FOR UPDATE SKIP LOCKED \+ SQLite fallback), ConfigurableOperation, CustomFieldRegistry, LinkRegistry (ModuleLink) и оркестратор саг WorkflowRunner с поддержкой compensate()1.  
> 2. **Фаза 2 — Расщепление sales, ликвидация коллизии Fulfillments и отвязка от сырого sku:** выделить автономные пакеты tax и payment; переименовать складское списание в inventory из Fulfillments в StockCommit и перевести таблицы pricing и inventory со строкового sku на (sellable\_type, sellable\_id) и иммутабельный InventoryItemId; переименовать shipping в fulfillment как единственного владельца Shipment; разделить sales на пакеты cart (с перехватчиками CartTransform и FailurePolicy) и orders, переведя чекаут на компенсирующую сагу PlaceOrderWorkflow1.  
> 3. **Фаза 3 — Промышленный каталог, фасетный поиск, рынки и хранимая стоимость (stored-value):** реализовать в catalog режим наследования атрибутов overridable\_variant, комплекты InventoryKit, SmartCollection, ProductRelations, медиа-слоты AssetAttachment, таблицу SEO-маршрутов с авто-редиректами, порт CatalogSearchPort (фасеты \+ cursor-пагинация), пакетный upsertMany и денормализованную витринную проекцию StorefrontProjection; добавить в pricing агрегат Market, 30-дневный леджер lowestPriceLast30Days (EU Omnibus), формульные прайс-листы и StagedPrice; внедрить в promotions двухфазный холд/возврат купонов при отмене, CampaignBudget, авто-подарки и NearMissHint; разработать модуль stored-value для подарочных карт и CustomerWallet1.  
> 4. **Фаза 4 — Асинхронный эквайринг, пост-продажный OMS, ПВЗ и B2B/Подписки:** реализовать в payment иерархию PaymentCollection / PaymentSession (requires\_action, Multicapture, IncrementalAuthorization, детализацию строк PaymentLineItems и правила PaymentCustomization); внедрить в orders двухфазный OrderChange (preview / confirm), процесс физического возврата Return (RMA с разделением restock и writeOff брака), OrderExchange, OrderClaim, DraftOrder и маршрутизатор OrderRouter (FulfillmentOrder); добавить в fulfillment порт ПВЗ (PickupPointProvider), BOPIS, частичные адреса, ParcelPacker и таможенные поля; внедрить универсальный SellingPlan и B2B-контур (StagedQuote, PaymentTerms, ApprovalFlow, SellerRef)6.  
> 5. **Фаза 5 — Экосистемные мосты CMS, вебхуки и контрактный testkit:** реализовать двусторонний шлюз вебхуков и GDPR-анонимизацию; собрать четыре изолированных пакета-моста bridge-unbox (workspace\_id \+ продажа сущностей CMS через SellableProvider), bridge-vaulter (медиа, выдача цифровых товаров и шифрование PII), bridge-azguard (проверка RBAC-скоупов и аудит действий акторов) и bridge-chatom (триггеры уведомлений и создание DraftOrder прямо из чатов), покрыв все порты сертификационными контрактными трейтами и стресс-тестами конкурентных блокировок в testkit1.

#### **Матрица критериев приёмки (Definition of Done) по фазам для автоматической самопроверки AI-агента**

| Фаза | Артефакты кода и миграций на выходе | Обязательные автоматические проверки (PHPStan / Deptrac / Pest \+ testkit) |
| :---- | :---- | :---- |
| **Фаза 1 (core)** | Единый неймспейс, CoreSchema::table(), ULID PK во всех миграциях, TenantContext, Quantity, DatabaseLockStrategy, LinkRegistry, CustomFieldRegistry, WorkflowRunner | 1\) Нулевое число BIGINT суррогатных PK и глобальных UNIQUE без tenant\_id; 2\) Зелёный тест конкурентных блокировок и на SQLite, и на PostgreSQL/MySQL; 3\) Юнит-тест каскадного compensate() при сбое внутри цикла шага саги1 |
| **Фаза 2 (Границы модулей)** | Выделенные пакеты tax, payment, cart, orders, fulfillment; StockCommit в inventory; замена строкового sku на SellableRef и InventoryItemId; сага PlaceOrderWorkflow | 1\) Архитектурный тест Deptrac подтверждает 0 прямых импортов между доменными пакетами; 2\) Интеграционный тест симулирует падение БД на шаге 4 чекаута и проверяет автоматический вызов cancelAuthorization и возврат стока/купона1 |
| **Фаза 3 (Каталог, Цены, Кошельки)** | Наследование overridable\_variant, InventoryKit, SmartCollection, cmz\_url\_routes, CatalogSearchPort (фасеты \+ курсоры), upsertMany, Market, 30-дневный Omnibus, CampaignBudget, пакет stored-value | 1\) Тест дизъюнктивных фасетов и курсорной пагинации без дублей; 2\) Пакетный upsertMany 10 000 SKU укладывается в целевой бюджет запросов; 3\) Проверка INV-SV-001 и возврата лимита купона при отмене заказа1 |
| **Фаза 4 (Эквайринг, OMS, Логистика, B2B)** | PaymentCollection (requires\_action, Multicapture, IncrementalAuthorization), OrderChanges (preview / confirm), Return (restock vs writeOff), OrderExchange, DraftOrder, OrderRouter, ПВЗ, SellingPlan, StagedQuote | 1\) Сквозной сценарий OrderChange с доплатой, частичным возвратом и копеечной сверкой налогов; 2\) Проверка INV-INV-002 (приёмка брака не увеличивает продаваемый сток); 3\) Сценарий сплит-отгрузки с двумя частичными capture(isFinal: false / true)6 |
| **Фаза 5 (Мосты CMS и Сертификация)** | Шлюз исходящих/входящих вебхуков, Orders::erasePersonalData(), пакеты bridge-unbox, bridge-vaulter, bridge-azguard, bridge-chatom, контрактные трейты Asserts\*Compliance | 1\) Сценарий продажи кастомной сущности Unbox без установленного пакета catalog; 2\) Проверка изоляции двух workspace\_id с одинаковыми sku, slug и кодами купонов; 3\) Многопроцессный стресс-тест на отсутствие oversell последнего остатка1 |

#### **Источники**

> 1.   
> 2. commerz.zip  
> 3. Cart Transform Function API \- Shopify Dev Docs, [https\://shopify.dev/docs/api/functions/latest/cart-transform](https://shopify.dev/docs/api/functions/latest/cart-transform)  
> 4. 4.4. Define Module Link \- Medusa Documentation, [https\://docs.medusajs.com/learn/fundamentals/module-links](https://docs.medusajs.com/learn/fundamentals/module-links)  
> 5. ConfigurableOperationDef \| Vendure Docs, [https\://docs.vendure.io/current/framework/reference/typescript-api/configurable-operation-def](https://docs.vendure.io/current/framework/reference/typescript-api/configurable-operation-def)  
> 6. [https\://docs.commercetools.com/api/projects/order-edits](https://docs.commercetools.com/api/projects/order-edits)  
> 7. Capture a payment multiple times \| Stripe Documentation, [https\://docs.stripe.com/payments/multicapture?platform=web\&ui=elements](https://docs.stripe.com/payments/multicapture?platform=web&ui=elements)  
> 8. 4.8.2. Compensation Function \- Medusa Documentation, [https\://docs.medusajs.com/learn/fundamentals/workflows/compensation-function](https://docs.medusajs.com/learn/fundamentals/workflows/compensation-function)  
> 9. Staged Standalone Prices Available in Beta \| API, [https\://docs.commercetools.com/api/releases/2022-08-15-staged-standalone-prices-available-in-beta](https://docs.commercetools.com/api/releases/2022-08-15-staged-standalone-prices-available-in-beta)  
> 10. Promotion Module Data Models Reference \- Medusa Documentation, [https\://docs.medusajs.com/resources/references/promotion/models](https://docs.medusajs.com/resources/references/promotion/models)  
> 11. Inventory Kits \- Medusa Documentation, [https\://docs.medusajs.com/resources/commerce-modules/inventory/inventory-kit](https://docs.medusajs.com/resources/commerce-modules/inventory/inventory-kit)  
> 12. Manage Inventory Items in Medusa Admin, [https\://docs.medusajs.com/user-guide/inventory/inventory](https://docs.medusajs.com/user-guide/inventory/inventory)  
> 13. Payment Customization Function API \- Shopify Dev Docs, [https\://shopify.dev/docs/api/functions/latest/payment-customization](https://shopify.dev/docs/api/functions/latest/payment-customization)  
> 14. Links between Order Module and Other Modules, [https\://docs.medusajs.com/resources/commerce-modules/order/links-to-other-modules](https://docs.medusajs.com/resources/commerce-modules/order/links-to-other-modules)  
> 15. Increment an authorization \| Stripe Documentation, [https\://docs.stripe.com/payments/incremental-authorization](https://docs.stripe.com/payments/incremental-authorization)  
> 16. Order Routing Location Rule Function API \- Shopify Dev Docs, [https\://shopify.dev/docs/api/functions/latest/order-routing-location-rule](https://shopify.dev/docs/api/functions/latest/order-routing-location-rule)  
> 17. [https\://docs.medusajs.com/resources/commerce-modules/order/order-change](https://docs.medusajs.com/resources/commerce-modules/order/order-change)  
> 18. Quotes overview \| API, [https\://docs.commercetools.com/api/quotes-overview](https://docs.commercetools.com/api/quotes-overview)  
> 19. API Extensions \- commercetools Documentation, [https\://docs.commercetools.com/api/projects/api-extensions](https://docs.commercetools.com/api/projects/api-extensions)  
> 20. 3.2.1. Guide: Define Module Link Between Brand and Product, [https\://docs.medusajs.com/learn/customization/extend-features/define-link](https://docs.medusajs.com/learn/customization/extend-features/define-link)  
> 21. Fulfillment Constraints Function API \- Shopify Dev Docs, [https\://shopify.dev/docs/api/functions/latest/fulfillment-constraints](https://shopify.dev/docs/api/functions/latest/fulfillment-constraints)  
> 22. Staged and active Standalone Prices achieved general availability, [https\://docs.commercetools.com/api/releases/2023-06-27-staged-and-active-standalone-prices-achieved-general-availability-status](https://docs.commercetools.com/api/releases/2023-06-27-staged-and-active-standalone-prices-achieved-general-availability-status)  
> 23. Campaign \- Medusa Documentation, [https\://docs.medusajs.com/resources/commerce-modules/promotion/campaign](https://docs.medusajs.com/resources/commerce-modules/promotion/campaign)  
> 24. Order Module Data Models Reference \- Medusa Documentation, [https\://docs.medusajs.com/resources/references/order/models](https://docs.medusajs.com/resources/references/order/models)  
> 25. Pickup Point Delivery Option Generator Function API, [https\://shopify.dev/docs/api/functions/unstable/pickup-point-delivery-option-generator](https://shopify.dev/docs/api/functions/unstable/pickup-point-delivery-option-generator)  
> 26. Order Object \| Saleor Commerce Documentation, [https\://docs.saleor.io/api-reference/orders/objects/order](https://docs.saleor.io/api-reference/orders/objects/order)  
> 27. 11.4. From Medusa v1 to v2: Conceptual Differences, [https\://docs.medusajs.com/learn/introduction/from-v1-to-v2](https://docs.medusajs.com/learn/introduction/from-v1-to-v2)  
> 28. 4.4.1. Module Link Direction \- Medusa Documentation, [https\://docs.medusajs.com/learn/fundamentals/module-links/directions](https://docs.medusajs.com/learn/fundamentals/module-links/directions)  
> 29. 4.4.3. Add Columns to a Link Table \- Medusa Documentation, [https\://docs.medusajs.com/learn/fundamentals/module-links/custom-columns](https://docs.medusajs.com/learn/fundamentals/module-links/custom-columns)  
> 30. Extend Product Data Model \- Medusa Documentation, [https\://docs.medusajs.com/resources/commerce-modules/product/extend](https://docs.medusajs.com/resources/commerce-modules/product/extend)  
> 31. Extend Cart Data Model \- Medusa Documentation, [https\://docs.medusajs.com/resources/commerce-modules/cart/extend](https://docs.medusajs.com/resources/commerce-modules/cart/extend)  
> 32. Medusa Examples, [https\://docs.medusajs.com/resources/examples](https://docs.medusajs.com/resources/examples)  
> 33. Types \| Vendure Docs, [https\://docs.vendure.io/current/framework/reference/graphql-api/shop/object-types](https://docs.vendure.io/current/framework/reference/graphql-api/shop/object-types)  
> 34. I18nService \| Vendure Docs, [https\://docs.vendure.io/current/framework/reference/typescript-api/common/i18n-service](https://docs.vendure.io/current/framework/reference/typescript-api/common/i18n-service)  
> 35. Cart and Checkout Validation Function API \- Shopify Dev Docs, [https\://shopify.dev/docs/api/functions/latest/cart-and-checkout-validation](https://shopify.dev/docs/api/functions/latest/cart-and-checkout-validation)  
> 36. Links between Product Module and Other Modules, [https\://docs.medusajs.com/resources/commerce-modules/product/links-to-other-modules](https://docs.medusajs.com/resources/commerce-modules/product/links-to-other-modules)  
> 37. Product Variant Inventory \- Medusa Documentation, [https\://docs.medusajs.com/resources/commerce-modules/product/variant-inventory](https://docs.medusajs.com/resources/commerce-modules/product/variant-inventory)  
> 38. Create Multi-Part Product in Medusa Admin, [https\://docs.medusajs.com/user-guide/products/create/multi-part](https://docs.medusajs.com/user-guide/products/create/multi-part)  
> 39. Create Product in Medusa Admin \- Medusa Admin User Guide, [https\://docs.medusajs.com/user-guide/products/create](https://docs.medusajs.com/user-guide/products/create)  
> 40. Manage Product Variants in Medusa Admin, [https\://docs.medusajs.com/user-guide/products/variants](https://docs.medusajs.com/user-guide/products/variants)  
> 41. Delivery Customization Function API \- Shopify Dev Docs, [https\://shopify.dev/docs/api/functions/latest/delivery-customization](https://shopify.dev/docs/api/functions/latest/delivery-customization)  
> 42. Increment an authorization \| Stripe Documentation, [https\://docs.stripe.com/payments/incremental-authorization?platform=web\&ui=elements](https://docs.stripe.com/payments/incremental-authorization?platform=web&ui=elements)  
> 43. Flexible payment scenarios \- Stripe Documentation, [https\://docs.stripe.com/payments/flexible-payments](https://docs.stripe.com/payments/flexible-payments)  
> 44. Capture a PaymentIntent \| Stripe API Reference, [https\://docs.stripe.com/api/payment\_intents/capture](https://docs.stripe.com/api/payment_intents/capture)  
> 45. Use payment line items for flexible \- Stripe Documentation, [https\://docs.stripe.com/payments/payment-line-items/flexible-payment-scenarios](https://docs.stripe.com/payments/payment-line-items/flexible-payment-scenarios)  
> 46. Payment line items \- Stripe Documentation, [https\://docs.stripe.com/payments/payment-line-items](https://docs.stripe.com/payments/payment-line-items)  
> 47. ReturnReason \- Order Module Data Models Reference, [https\://docs.medusajs.com/resources/references/order/models/ReturnReason](https://docs.medusajs.com/resources/references/order/models/ReturnReason)  
> 48. Function APIs \- Shopify Dev Docs, [https\://shopify.dev/docs/api/functions/latest](https://shopify.dev/docs/api/functions/latest)  
> 49. Product and Variant Physical and Customs Attributes, [https\://docs.medusajs.com/resources/commerce-modules/product/physical-customs-attributes](https://docs.medusajs.com/resources/commerce-modules/product/physical-customs-attributes)  
> 50. Staged Quotes \| API, [https\://docs.commercetools.com/api/projects/staged-quotes](https://docs.commercetools.com/api/projects/staged-quotes)  
> 51. Explore the B2B sample data \| Merchant Center, [https\://docs.commercetools.com/merchant-center/getting-started/explore-b2b-sample-data](https://docs.commercetools.com/merchant-center/getting-started/explore-b2b-sample-data)

[image1]: <data:image/png;base64,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>

[image2]: <data:image/png;base64,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>

[image3]: <data:image/png;base64,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>

[image4]: <data:image/png;base64,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>

[image5]: <data:image/png;base64,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>