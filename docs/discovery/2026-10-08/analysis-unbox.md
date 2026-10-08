# Analysis Unbox: каталог, свойства, предложения, магазины и склад

Дата: 2026-10-08. Статус: самостоятельный исследовательский материал для проектирования Commerz и последующей проверки Claude Code. Здесь зафиксирована фактическая реализация Unbox и её ограничения; предлагаемые изменения относятся к будущему Commerz. Unbox не изменялся.

**Главный результат.** Unbox уже содержит полезную небольшую модель: товар, выбираемые свойства, словари значений и товарные предложения как комбинации значений. Рядом существуют отдельные описательные характеристики и content blocks. Это хороший реальный сценарий для Commerz, но текущую реализацию нельзя считать завершённым универсальным commerce engine: склад пока представлен справочником продавца и одним числом остатка у предложения, а checkout/payment workflow не подтверждён прочитанным кодом.

## Как читать материал без повторного сканирования Unbox

Исходный репозиторий: `/home/vostrikov/projects/unbox`, HEAD `4e0d338c5316bb5e216239c4bfb0b62ce60c5a88`. Worktree dirty: только найденная untracked директория `tests/Feature/Media/`; её содержимое не использовалось. Root `CLAUDE.md` прочитан; tracked `AGENTS.md` в проверенной области не найден. Никакие tests, migrations, Artisan, Composer install или application bootstrap не запускались. Живая БД не открывалась: «схема» далее означает восстановленную схему по 38 tracked migrations, а не сверенный production dump.

Для независимого ревью всё необходимое положено **в Commerz**:

| Артефакт | Содержимое |
| --- | --- |
| [unbox/source-manifest.json](unbox/source-manifest.json) | HEAD, dirty status, timestamp, версии выбранных зависимостей; 143 полных source snapshots и отдельное дополнение с SHA-256/выбранными строками пяти файлов DB configuration |
| [unbox/schema-dictionary.md](unbox/schema-dictionary.md) | Все поля и Blueprint declarations 38 таблиц: Catalog 21, Sales 12, Buyer 5 |
| [unbox/schema-sources.md](unbox/schema-sources.md) | Полные миграции с исходными номерами строк |
| [unbox/models-sources.md](unbox/models-sources.md) | Полные выбранные модели и enums с исходными номерами строк |
| [unbox/workflows-sources.md](unbox/workflows-sources.md) | Формы, компоненты, repositories, маршруты, корзина и ancillary code |
| [unbox/tests-sources.md](unbox/tests-sources.md) | Прочитанные ключевые тесты, включая действительную глубину assertions |
| [unbox/file-index.txt](unbox/file-index.txt) | Отфильтрованный tracked file inventory |
| [unbox/table-index.json](unbox/table-index.json) | Машинный список 38 migration tables |
| [unbox/database-config-evidence.md](unbox/database-config-evidence.md) | Безопасные excerpts database config, Compose, Dockerfile и README: declared MySQL 8.4 и Laravel fallback SQLite |
| [unbox/alternative-design-review.md](unbox/alternative-design-review.md) | Независимый второй взгляд: пять способов хранения, сценарии проверки и границы трактовки «шести» |

Пути и номера строк далее исходные, относительно Unbox. Полные snapshots позволяют открыть нужный фрагмент внутри Commerz, даже если Unbox продолжит меняться. Секреты, `.env`, пользовательские данные, uploads и vendor не копировались.

Уровни уверенности: **факт** — непосредственно прочитанный код/DDL; **вывод** — следствие прочитанного механизма без запуска; **кандидат** — вариант для Commerz. Отсутствие механизма означает «не найден в описанной области поиска», а не доказанное отсутствие во всех интеграциях и внешних сервисах.

## 1. Unbox — приложение с модулями, а не независимый пакет

Root Composer требует PHP `^8.4`, Laravel `^12.0`, `nwidart/laravel-modules ^12.0`, Filament `^4.0`. В lock зафиксированы Laravel `v12.43.1`, modules `v12.0.4`, Filament `v4.3.1`, Livewire `v3.7.2`, `lukepolo/laracart 2.6.1`, Spatie Data `4.18.0`, MediaLibrary `11.17.7`, QueryBuilder `6.3.6`, Pest `v4.2.0`. Это версии **lock**, не проверенный runtime PHP и не результат dependency resolution сегодня.

**Какая база данных используется.** В объявленной Docker-инфраструктуре — **MySQL 8.4**: `docker-compose.yml:210–212` содержит сервис `mysql` с `image: mysql:8.4`, а PHP services получают defaults `DB_CONNECTION=mysql`, `DB_HOST=mysql`, `DB_PORT=3306` (`:42–44`, `:93–95`, `:141–143`, `:185–187`). Это env-configurable defaults. При запуске вне этой конфигурации Laravel fallback иной: `config/database.php:21` задаёт `env('DB_CONNECTION', 'sqlite')`; там также присутствуют конфигурации mysql/mariadb/pgsql/sqlsrv. `README.md:25` заявляет MySQL 8.0+, PostgreSQL 13+ и SQLite для разработки, но совместимость migrations со всеми этими engines здесь не проверялась. `Dockerfile:1` закрепляет PHP image `serversideup/php:8.4-fpm-nginx`. **Фактически работающий runtime engine неизвестен:** `.env`, config cache и живая БД не проверялись. [Безопасные исходные excerpts и hashes](unbox/database-config-evidence.md) позволяют подтвердить эти утверждения без раскрытия credentials.

`Modules/Catalog/composer.json:16–22` отображает `Catalog\\` в `app/`. Поэтому актуальны `Modules/Catalog/app/Enums/Product/*`; параллельные tracked `Modules/Catalog/Enums/*` не входят в этот PSR-4 mapping. Наличие старого файла с похожим названием не доказывает его участие в запросе.

Схема и модели тесно связаны с host application: Catalog импортирует `App\\Models\\User`, Sales Shop; ProductOffer импортирует Buyer Order Item; enums реализуют Filament `HasLabel`. Миграции часто получают table name через `new Model()->getTable()`. Для приложения это удобный стиль, для повторно используемого пакета — предмет отдельного разделения contracts и host adapters. При изменении `$table` модели меняется результат старой migration, поэтому этот механизм не является immutable schema description.

Префиксы: `cat_`, `sales_`, `buyer_`; модели hard-code table names. Единого configurable table resolver, аналогичного Chatom/Vaulter/AzGuard, в изученной commerce области не найдено.

## 2. Термины, которые в Unbox нельзя объединять одним словом «свойство»

| Термин в коде | Фактический смысл | Что важно для Commerz |
| --- | --- | --- |
| `Product` | Карточка одного магазина: название, описание, price fallback, физические габариты, merchandising flags | Не тождествен SKU и не тождествен глобальному master product |
| `Property` | Определение выбираемой характеристики: имя, код, storage type, UI control, unit, владелец shop или shared | Definition отдельно от значения и отдельно от UI |
| `PropertyValue` | Возможное значение в словаре свойства: label/code + одно из typed fields | Это option dictionary value; не значение произвольного атрибута конкретного товара |
| `ProductProperty` | Свойство подключено к товару; JSON `available_codes` ограничивает допустимую палитру | Product-level applicability и allowed-value subset |
| `ProductOffer` | Комбинация значений с ценой и единым inventory_quantity | Ближе всего к variant/SKU, хотя SKU code как поле отсутствует |
| `ProductOfferPropertyValue` | Выбранное значение свойства у предложения | Variant selection, а не самостоятельный scalar attribute |
| `CharacteristicsForm` | Вес, длина, ширина, высота, measure_ratio на Product | Фиксированные operational fields |
| `SpecificationGroup` / product group / option | Группированный описательный контент, options имеют JSON `{name,value}` | Presentation specification; не typed searchable dictionary |
| `ProductBlock` | Контент карточки типа text/image/video с JSON | CMS content, отдельный lifecycle и indexing |
| `ProductColor` | Product + color code, объект MediaLibrary для изображений цвета | Media grouping по option, не основная таблица выбора цвета |
| `Shop` | Магазин одного Seller, к нему относится Product | Возможный storefront scope, но здесь также owner каталога |
| `Warehouse` | Именованный склад Seller с city_id | Справочник мест; нет связи со stock balance в найденной схеме |

Основания: `Catalog/app/Models/Product/Product.php:176–217`, `ProductOffer.php:48–83`, `Catalog/app/Models/Property.php:36–78`, migrations свойств и предложений; `Sales/app/Livewire/Forms/Product/CharacteristicsForm.php:13–21`; `SpecificationForm.php:125–142`; `ProductColor.php:12–43`.

### Что означает ограниченный набор «до шести сложных свойств»

Точная формулировка пользователя: «свойства товара, они зафиксированы, ну то есть больше там шести свойств товара никогда не будет. Это очень сложные товары, и мы, получается, каждый из них вот так вот проектируем».

Это входная характеристика сценария пользователя, **не подтверждённый hard limit Unbox**. В форме `properties` проверяется как array без `max:6` (`Sales/app/Livewire/Forms/Product/PropertiesForm.php:27–42`), в таблице число rows не ограничено. В коде найдено три storage types и два seeded shared definitions. Нельзя молча превратить эти три разных числа в одно:

- небольшой фиксированный набор **семантических типов** может обслуживать много definitions;
- до шести **definitions/вариативных осей у товара** не ограничивает число variant combinations;
- шесть фиксированных **бизнес-полей всей системы** иногда лучше моделировать отдельными columns/entities.

Выбор storage нужно проверять на конкретных сценариях каждого свойства: его cardinality, поиск, переводы, unit, применение к product/variant и участие в формировании варианта. Малое число типов само по себе не оправдывает ни universal EAV, ни полное размещение в JSON.

## 3. Основная схема: точные поля и связи

Полная исходная схема находится в [словаре 38 таблиц](unbox/schema-dictionary.md). Ниже — её семантически важная часть. Все ID основных таблиц созданы `$table->id()`; внешние ссылки — `foreignId`. Настройки UUID/ULID и independent host key mode в этих migrations отсутствуют.

### 3.1 Определения и значения свойств

| Таблица | Поля | Constraints / lifecycle |
| --- | --- | --- |
| `cat_properties` | `id`; nullable `shop_id`; `type` string(12), default text; nullable `control` string(16), `unit` string(12); `name` string(50); nullable indexed `code` string(60); `sort_order` unsigned smallint default 1 | shop FK `nullOnDelete`; нет unique(code) или unique(shop_id,code); timestamps отсутствуют |
| `cat_property_values` | `id`; `property_id`; nullable `label` string(32), `code` string(32), `value_text` string, `value_integer` bigint, `value_decimal` decimal(12,4) | property FK cascade; отдельные индексы code и три value columns; нет unique(property_id,code), нет constraint «ровно один typed value»; timestamps отсутствуют |
| `cat_product_properties` | `id`; `product_id`; `property_id`; nullable JSON `available_codes` | оба FK cascade; unique(product_id,property_id); timestamps отсутствуют |
| `cat_product_offer_property_values` | `id`; `product_offer_id`; `property_value_id` | оба FK cascade; unique(product_offer_id,property_value_id) с коротким именем `cpo_pv_unique` |

Основания: миграции `2025_10_19_185330`, `_185332`, `_185334`, `_185427`, полностью сохранённые в schema sources. `Property` casts `type/control/unit` к PHP enums; `PropertyValue` casts integer и decimal:4 (`Catalog/app/Models/Property.php:73–79`, `PropertyValue.php:44–48`).

`type`, `control` и `unit` уже раздельны на уровне полей. Реально доступные варианты:

| Ось | Значения в действующем enum | Комментарий |
| --- | --- | --- |
| storage type | `text`, `integer`, `decimal` | `boolean` закомментирован |
| control | `input`, `select`, `colorpicker` | textarea/checkbox закомментированы |
| unit | `unit` | полноценной системы единиц/конверсии нет |

Источники: `Modules/Catalog/app/Enums/Product/{PropertyType,PropertyControl,PropertyUnit}.php`. Для colorpicker форма принудительно выбирает text, но это application behavior, а не DB invariant.

### 3.2 Товар и предложение

`cat_products` содержит:

- ownership: `shop_id` cascade FK, `created_by_id` FK, nullable `brand_id` null-on-delete;
- merchandising: `name`, nullable subtitle/description/content JSON, globally unique slug, sort_order;
- price: nullable `price`, `old_price`, объявленные **float(12,2)**;
- физические данные: nullable unsigned `base_weight`, `base_length/width/height`, `measure_ratio` default 1;
- `inventory_policy` string(16), `condition` string(10), flags is_new/is_sale/is_discount/is_hit/is_active;
- nullable indexed published_at, status default draft, timestamps и softDeletes.

`cat_product_offers` содержит `product_id`, **float(12,2) price default 0**, integer inventory_quantity default 0, is_active, timestamps, softDeletes. Нет SKU/barcode, currency, warehouse/location, reserved quantity, version, combination key или external source identity. См. migrations `2025_10_19_185331:21–49`, `2025_10_19_185426:18–25`.

`Product::properties()` — many-to-many определения; `Product::offers()` — hasMany вариантов. `ProductOffer::propertyValues()` — many-to-many значений. На один product можно создать offer без values. Код `canChangeProperties()` возвращает false при существовании offers, а `isPropertyUsedInOffers()` ищет использование конкретного свойства (`Product.php:215–229`).

### 3.3 Описательные характеристики и media

| Таблица | Смысл | Фактическая структура |
| --- | --- | --- |
| `cat_specification_groups` | Общее название группы характеристик | name(100), nullable creator, sort_order, timestamps, softDeletes |
| `cat_product_specification_groups` | Группа у конкретного товара | product_id, group_id → specification_groups, content JSON, sort_order |
| `cat_product_specification_options` | Строка описательной характеристики | product_id, group_id → product_specification_groups, content JSON, sort_order |
| `cat_product_blocks` | Контентный блок товара | product_id, type(12), nullable title(50), content JSON, sort_order |
| `cat_product_colors` | Media grouping цвета | product_id, nullable indexed code(32); модель HasMedia |

`SpecificationForm` сохраняет `content = ['name' => ..., 'value' => ...]`, обе части строковые; это не нормализованные числовые технические характеристики (`Sales/app/Livewire/Forms/Product/SpecificationForm.php:125–142`). `ProductColor.code` не имеет FK к `cat_property_values.code`, и такого code нельзя считать неизменяемым ID.

Категории, бренды, коллекции, теги — отдельные сущности и pivots; полные поля перечислены в словаре. В category tree используется nullable parent_id. Product categories/collections/tags и collection tags pivots в прочитанных migrations не имеют composite unique; это отличие от product-properties pivot, где unique есть.

### 3.4 Seller, Shop, Warehouse

`sales_sellers` имеет name/description и flags trusted/active/verified. `sales_shops` связан с seller_id; name, tagline, globally unique slug, is_active, timestamps, softDeletes. `sales_seller_warehouses` имеет только `id`, `name`, `seller_id`, `city_id`, timestamps. Компания, типы бизнеса, команда, permissions, settings и contacts — отдельные связанные таблицы.

**Факт:** warehouse не referenced ни из `cat_product_offers`, ни из `buyer_order_items`, ни из найденной отдельной stock table. **Вывод:** наличие справочника Warehouse не означает поддержку multi-warehouse accounting. `InventoryPolicy` фактически содержит `in_stock`/`out_of_stock` — label состояния доступности, а не policy backorder/reservation/oversell (`Sales/app/Enums/Shop/InventoryPolicy.php:9–19`).

### 3.5 Orders и refunds

| Таблица | Значимые поля | Что пока не следует из наличия таблицы |
| --- | --- | --- |
| `buyer_order_customers` | user_id, имя, контакты, comment, softDeletes | Не доказывает immutable customer snapshot |
| `buyer_orders` | user/shop/customer/address refs; indexed order_number; order/payment statuses; payment_method; gift-card amount, totals; tracking_number; pickup_date; comment | Нет найденного payment attempt/transaction/gateway intent model или неизменяемого address snapshot |
| `buyer_order_items` | order/product/offer refs; nullable JSON offer_properties; quantity; unit/total price, discount, tax, comment | JSON-снапшот предусмотрен, но его filling/схема при checkout не найдены |
| `buyer_order_updates` | order/user refs, action, JSON data, date_added | Не доказывает событийную модель или полный audit transitions |
| `buyer_order_refund_requests` | order/user refs, status, reason, amount/shipping/total/discount | Request не равен provider refund transaction или товарному возврату |

Все перечисленные денежные migration columns используют `float(12,2)`. Order number только index, не unique. Валюта в этих таблицах отсутствует. Item `offer_properties` fillable, но array/json cast в `Item::casts()` отсутствует (`Buyer/app/Models/Order/Item.php:55–65,89–97`); сам факт поля недостаточен, чтобы считать snapshot behavior законченной функцией.

Status enums реально присутствуют: order pending/paid/processing/shipped/delivered/cancelled; payment waiting/success/failed; refund pending/approved/rejected; payment method cash/card. Enum cases не определяют допустимые переходы, компенсации или atomicity. Filament OrderForm прямо позволяет выбрать status/payment status и ввести totals (`Buyer/app/Filament/Resources/Orders/Schemas/OrderForm.php:35–72`), поэтому это административная CRUD surface, а не доказанный checkout calculation engine.

## 4. Как сейчас работает создание свойств и вариантов

### 4.1 Актуальный seller UI

`Modules/Sales/routes/web.php:12–22` ведёт seller product routes в `Sales\\Livewire\\Pages\\Catalog\\Products\\ProductPage`; page отображает `sales::livewire.components.product-form`. Шаблон включает отдельные Livewire components для main, classification, characteristics, properties, offers, content, specification и media. Есть также старый `Sales/app/Livewire/Forms/ProductForm.php`, но текущая page использует раздельные формы. Исторические методы нельзя автоматически считать текущим write path.

Создание основной записи: `Forms/Product/ProductMainForm::save()` валидирует форму, внутри transaction создаёт/меняет Product; при создании берёт **первый** shop authenticated seller (`:130–161`). Формы дальнейших вкладок сохраняются отдельно. Это важный UX: карточка может существовать без offers и без всех характеристик.

### 4.2 Добавление определения свойства

`PropertiesForm::savePropertyFromModal()` имеет два режима (`:344–449`):

1. Shared: выбрать existing Property с `shop_id IS NULL`, загрузить values, применить available_codes к подготовленному массиву.
2. Custom: вычислить current shop через `Auth::user()?->seller?->shops()->first()`, создать/обновить Property этого shop, затем сохранить values.

**Существенная деталь:** custom definition и его values пишутся в БД уже при сохранении modal; product-property pivot синхронизируется отдельным `PropertiesForm::save()` (`:96–120`). Отмена дальнейшего редактирования товара не откатывает автоматически ранее записанный словарь. Common command boundary для этих двух действий в прочитанной цепочке нет.

Custom values сохраняются методом `savePropertyValues()`: все существующие values удаляются, затем создаются новые (`:489–500`). Они получают новые IDs. Поскольку offer-value pivot имеет FK cascade к values, такая операция способна удалить ранее существовавшие variant selections. Даже неизменённый текст значения не сохраняет его identity. Свойство shop-wide, поэтому затронутым может быть другой товар того же shop.

В UI некоторые controls disabled, если `canChangeProperties()` false. Но `PropertiesForm::save()` и `savePropertyFromModal()` не вызывают этот guard; component `saveProperties()` проверяет только editable/product, modal method делегирует без такой проверки (`Components/Forms/Product/PropertiesForm.php:29–35,77–79`). Это наблюдаемая разница между визуальной доступностью и server-side invariant; отдельный security/runtime audit здесь не проводился.

### 4.3 Что значит available_codes

`available_codes` хранится JSON в product-property pivot. `null`, пустой array и отсутствие значения обычно приводят к трактовке **без ограничений**, а не «запрещены все значения». Нужно явно определить требуемую семантику в Commerz.

Есть три несовпадающих прочитанных правила:

- seller `PropertiesForm::getProductPropertiesForOffers():131–136` фильтрует non-empty available_codes только для property code `color`/`size`;
- demo `ProductOffersRepository::generateOffers():47–63` делает то же;
- storefront `app/Livewire/Pages/Catalog/ProductPage::getAvailablePropertyValues():96–109` фильтрует **все shared properties** (`shop_id === null`), если codes непустые.

Следовательно новое shared свойство `material` может получить разный набор допустимых values в seller generator и storefront. Кроме того, code не имеет unique constraint в property-values table, так что code subset нельзя считать однозначным identity set.

### 4.4 Генерация предложений

Current seller `OffersForm::generateOffers()` получает все values подключённых properties, убирает свойства без values и строит декартово произведение (`:289–354`, `:442–468`). Комбинации целиком материализуются в PHP array. Уже имеющиеся комбинации сравниваются по sorted IDs и новым ключам (`:476–547`).

Отдельный `Sales/Repositories/Products/ProductOffersRepository::generateOffers()` выполняет похожую генерацию прямо в БД и задаёт остаток через `fake()->numberBetween(0,100)` (`:15–94`). Прочитанный caller — `app/Console/Commands/MakeDemo.php:295`; случайные остатки относятся к demo pathway. Нельзя приписывать эту случайную генерацию production seller save без доказательства другого caller.

Для ограниченного набора осей сложность всё равно растёт как произведение числа values:

| Оси | Число combinations |
| --- | ---: |
| 2 цвета × 3 размера | 6 |
| 6 осей × 3 значения | 729 |
| 6 осей × 10 значений | 1 000 000 |

Это арифметика, не benchmark. Верхний предел числа осей не решает explosion. Для Commerz нужно отдельно ограничивать число разрешённых combinations, поддерживать sparse variants и предварительно оценивать размер генерации. Не каждый descriptive attribute должен быть variant axis.

### 4.5 Сохранение предложений

`OffersForm::save():92–184` выполняет последовательность:

1. Валидирует вход; записывает Product.price/old_price.
2. По выбранному режиму меняет offer prices в форме.
3. Проверяет уникальность непустых combinations внутри массива формы.
4. Загружает existing offers; строит map по sorted property-value IDs.
5. Обновляет matching offers или создаёт новые; sync выбранных IDs.
6. Удаляет existing offers, комбинаций которых больше нет в форме.

В этой функции и вызывающем component `saveOffers()` нет общего transaction wrapper; base price записывается **до** проверки unique combinations. Вывод: ошибка последующей validation/SQL не обязана отменить уже записанную цену и предыдущие offers. Это вывод из порядка кода, не воспроизведённый runtime failure.

Validation проверяет существование `property_id` и `property_value_id` независимо (`OffersForm.php:29–41`), не доказывая, что value принадлежит этому property и разрешено текущему product/shop. При сохранении фактически используются plucked value IDs. В DB pivot нет property_id и ограничения `(offer,property)`.

## 5. Storefront, корзина и фактическая граница commerce workflow

`app/Livewire/Pages/Catalog/ProductPage` загружает product properties/values/offers, выбирает offer по URL или первый, показывает offer price с fallback на product price (`:29–86`). `availableOffers()` возвращает offers без явного active/stock predicate. Метод поиска best match считает совпавшие values (`:267+`); для строгого варианта с несколькими осями важно определить, допустимо ли частичное совпадение.

`ProductPage::addToCart():248–251` содержит TODO, но реально dispatches `cart:add`. Listener **реализован** в `Buyer/app/Livewire/Components/CartWidget.php:14–66`: он загружает offer, формирует property labels snapshot и вызывает LaraCart::add с product_id, price и options `{offer_id, properties}`. Поэтому утверждение «корзины вообще нет» было бы неверным.

В прочитанном listener есть проверка отсутствующего ID/offer, но нет проверки is_active, stock quantity или применения inventory policy. `Catalog/app/Livewire/Components/Product/Card.php:122–136` при отсутствии offer добавляет сам Product непосредственно в LaraCart; thus cart может содержать строку без offer_id, тогда как `buyer_order_items.offer_id` обязателен. Преобразование такого cart item в order item требует отдельного решения.

`Card::goToCheckout():142–144` только пишет сообщение в JS console. `CartSlideOver` позволяет удалить строку и показать total, его `noop()` не оформляет заказ. Поиск среди tracked app/Modules source не выявил завершённого place-order/reserve/payment orchestration. Это **ограничение найденной реализации**, а не утверждение о всех будущих планах Unbox.

Каталог фильтрует color через `offers.propertyValues`, condition на Product, price range/sorting на **Product.price**, а не минимальную доступную offer price (`Catalog/app/Repositories/ProductsRepository.php:77–108`). Если offer price отличается от base price, карточка и range filter могут опираться на разные цены. Для Commerz нужен явный pricing projection: «от», выбранный вариант, channel/customer quote и валюта.

## 6. Что в текущей схеме защищено и что остаётся на уровне соглашения

| Инвариант | Защита в изученной реализации | Практическое следствие для Commerz |
| --- | --- | --- |
| Свойство один раз подключено к product | DB unique(product_id,property_id) | Хороший минимальный invariant |
| Одна и та же value один раз у offer | DB unique(offer_id,value_id) | Не равно «одно value каждого property» |
| Один offer на конкретную combination | PHP проверка формы; DB unique key не найден | Конкурентные сохранения могут обойти локальную проверку; нужен DB-level identity |
| Value принадлежит выбранному property | Каждый FK существует отдельно | Нужна cross-reference validation и/или иной ключ |
| Property доступно текущему shop/product | Частично UI queries; общей записи через команду нет | `exists` не является ownership check |
| Ровно одно typed value соответствует Property.type | Некоторые Filament form callbacks обнуляют другие поля | Любой иной writer может записать несколько или ни одного поля |
| Изменение values сохраняет identity | Custom values delete + recreate | Ссылки variants могут исчезнуть через cascade |
| Недопустимо менять properties после offers | Метод модели + disabled UI | Server command должна повторять проверку под выбранной concurrency policy |
| Shared definition отдельно от локального | shop_id null / non-null | `nullOnDelete` превращает бывшее локальное property в shared candidate |
| Исторический offer сохраняется при заказе | Model deleting callback soft-deletes при orderItems | Обход model path и bulk delete имеют иной lifecycle; нужен единый командный контракт |
| Достаточно остатка | Integer на offer; UI min:0 | Нет доказанного reservation/oversell invariant |
| Money exact | Float migration columns, decimal display/model casts | Cast не меняет физический тип хранения и арифметику PHP float |
| Currency известна | Поля currency в изученной order/offer schema отсутствуют | Валюта должна быть обязательной частью price/quote/order contract |
| Order totals отражают immutable calculation | Nullable snapshot field, editable totals CRUD | Нужна фиксированная receipt/quote модель и formula/version provenance |

Два тонких примера:

1. **Один offer, два цвета.** Pivot uniqueness позволяет привязать value black и value white: IDs различны. В таблице нет property_id, который позволил бы запретить две строки одной axis. Текущий consumer часто выбирает `firstWhere(property_id)`, поэтому неправильная запись может интерпретироваться неоднозначно.
2. **Удаление магазина и shared dictionaries.** `cat_properties.shop_id` становится NULL по FK; shared query именно `whereNull(shop_id)`. Значит private definition после удаления owner может попасть в shared vocabulary. Для Commerz необходим явный definition scope/type, а не случайное повышение scope через null-on-delete.

Это не список выполненных исправлений Unbox; это проверяемые lessons learned для нового пакета.

## 7. Удаление, история и реальные тесты

`ProductOffer::delete()` вызывает forceDelete; model `deleting` callback при наличии orderItems делает soft delete и возвращает false (`Catalog/app/Models/Product/ProductOffer.php:94–120`). Это намерение сохранить historical references, но поведение достаточно необычно, чтобы проверять command-level тестами отдельно. Component `deleteAllOffers()` вызывает relation bulk delete (`Sales/app/Livewire/Components/Forms/Product/OffersForm.php:74–81`), то есть пользуется другим entry point. В этой работе не исполнялась семантика ORM и не утверждается одинаковое поведение обоих путей.

`tests/Feature/ProductOffersIntegrationTest.php` — 28 строк reflection assertions: существование `boot`, `delete`, `orderItems`; тест прямо не проверяет удаление в БД. `tests/Feature/ProductFormTest.php` — 7 строк smoke GET `/`. Их названия не являются доказательством сохранения заказа, offer-values или конкурентных invariants. Другие seller page tests есть и сохранены в evidence; этот обзор не измеряет общую coverage Unbox.

Для следующего проектирования полезнее fixtures, которые создают product/shared/local property, выбранные values и offer, затем воспроизводят поведение через публичную команду. Пять первоочередных сценариев: изменён label при сохранении ID; попытка удалить value уже заказанного SKU; повторная generation; две конкурентные одинаковые combinations; ограничение allowed values для нового shared свойства, отличного от color/size.

## 8. Какие идеи Unbox стоит перенести в Commerz

1. **Разделение product и purchasable variant.** Не перегружать Product stock и variant options. Для простого продукта решить, создавать ли default variant; устранить неоднозначность cart строки без offer.
2. **Три независимые оси description.** Storage type, semantic definition и UI control уже разделены частично; развить это в typed definitions и adapters, не привязывая core enum к Filament.
3. **Общий словарь и ограничение на уровне товара.** Shared color palette + разрешённое подмножество на продукт полезны, если identity и ownership стабильны. Лучше ссылаться на option IDs/стабильные ключи, а значения code делать уникальными в явном scope.
4. **Фиксированные operational fields отдельно от расширяемых атрибутов.** Shipping dimensions/weight часто заслуживают типизированных value objects и нормальных columns; descriptive content может оставаться JSON.
5. **Различие options и technical specification.** Цвет/размер формируют вариант; «страна производства» или «инструкция по уходу» обычно не должны увеличивать число SKU.
6. **Media принадлежит варианту/option grouping.** ProductColor указывает на реальную потребность картинок выбранного цвета. В новой модели связь должна иметь стабильный foreign identity, а не только строковый color code.
7. **Исторические snapshot values.** Намерение `offer_properties` и сохранения offer при заказе полезно; реализовать как immutable order line description с явной схемой, версией и независимостью от текущего dictionary.
8. **Небольшие независимо сохраняемые UI slices.** Это удобно для большого product editor, но сохранение каждого slice должно обращаться к единому domain contract и сохранять atomicity своих изменений.

## 9. Какие решения нельзя переносить буквально

| Текущее решение | Почему недостаточно для повторного использования | Направление следующего эксперимента |
| --- | --- | --- |
| Hard-coded color/size conditions | Новое свойство меняет поведение между seller/storefront | Единственный `AllowedOptionSet` resolver |
| Shared через shop_id=null | Scope теряется при удалении owner | Explicit scope + lifecycle policy |
| Delete/recreate option dictionary | Теряется stable identity и связи historical variants | Version/retire/update-by-ID; запрет destructive change по usage |
| UI owns writes | Import/API/job вынуждены копировать rules | Command/module с typed input/result |
| Все значения → полное cartesian product | Explosive memory/row count, невозможные combinations | Explicit sparse variants; budgeted generator; preview cardinality |
| Float price + decimal cast | Exactness не гарантируется | Money decimal/minor units с currency и rounding policy |
| Single quantity per offer | Нет reservation, warehouses, reconciliation | Stock item × location + движения/резервы как отдельный module |
| Arbitrary enum defaults в форме | Неизвестное type/control тихо превращается в Text/Input | Reject unknown type; versioned type registry |
| Первое shop у seller | Невидимый multi-shop context | Explicit shop/channel в каждом command |
| Model-level deletion magic | Разные entry points дают разные guarantees | Explicit archive/remove operations + historical refs |
| Generic `exists` rule | Не доказывает parent/owner membership | Scoped lookup под authenticated context |
| Product.price для sorting, offer.price для покупки | Неоднозначная price projection | Единая опубликованная price projection и re-quote на checkout |

## 10. Варианты хранения малого набора сложных свойств

Это comparison кандидатов, а не принятое архитектурное решение.

| Подход | Когда подходит | Сильная сторона | Цена/ограничение |
| --- | --- | --- | --- |
| Фиксированные typed columns | Небольшой стабильный набор scalar operational properties | Constraints, предсказуемые queries, прозрачная схема | Новый доменный вид требует migration; плохо для многозначности и локализации |
| Отдельные typed child entities | Свойство имеет собственные связи, единицы, media, ranges, lifecycle | Сильная предметная модель и constraints | Больше таблиц и явных методов; не единый универсальный editor |
| JSON + typed registry/validation | Слабоструктурированный редкий контент, разные shapes, низкая query нагрузка | Простая запись целого document, новые semantic types | Search/indexing, cross-ref integrity и bulk type migration отдельно |
| Typed attribute-value rows | В разных проектах много разных definitions, нужны filter/sort и scoped values | Гибкость definitions без migration каждого имени | Ownership, cardinality, locale, scope, indexes и versioning усложняют schema |
| Shared option dictionaries + variant selections | Конечный набор selectable options, формирующий SKU | Stable option identity и reusable vocabularies | Не покрывает arbitrary text/measurements и автоматически не гарантирует combination uniqueness |
| Гибрид | Смешаны operational fields, variant options и descriptive content | Каждая категория получает подходящие guarantees | Нужен ясный routing и общий metadata contract, чтобы consumers не разбирали пять схем |

Фактический Unbox уже **гибрид**: weight/dimensions columns; shared/local dictionaries с typed value columns; variant selections pivots; descriptive specification JSON; rich-content JSON; color media entity. Проблема не в самом наличии нескольких форм хранения, а в незадокументированной семантике и отсутствии единых invariant checks между writers.

### Проверки, которые должны определить выбор

- Товар с цветом/размером: словарь → допустимое подмножество → несколько реально продаваемых variants; новый color label не меняет variant ID.
- Ткань: width/length decimal + unit, продажа fractional quantity, stock в другой единице; option dictionary не должен заменять измеряемую величину.
- Электроника: RAM/storage как variant options, wattage как searchable fixed measurement, translated descriptive text; не все три превращаются в Cartesian axis.
- Персонализация: покупатель вводит текст гравировки при заказе; это line configuration, а не заранее созданный millionth variant.
- Один тип свойства в двух host проектах: один UI — Filament, другой — headless API; тип и validation общие, widgets различны.
- Развитие типа: decimal precision/unit меняется после существующих orders; order snapshot остаётся неизменным, catalog values мигрируют явной операцией.

Для профиля Unbox разумно **проверить** усиленный гибрид прежде, чем вводить universal EAV всех данных. Для Commerz как переиспользуемого package важнее стабильный contract определения и query capabilities, чем обязательность одного физического storage для любых свойств.

## 11. Конкретный backlog для следующего исследования, без реализации

| Вопрос | Артефакт следующего этапа | Критерий достаточности |
| --- | --- | --- |
| Что именно ограничено шестью | Набор 4–6 пользовательских product scenarios | Понятны type/definition/axis, cardinality, unit, localization, search |
| Где заканчивается Product и начинается Variant | Небольшая schema + command example | Simple product и configurable product имеют однозначный purchasable ID |
| Как задаются option scope и allowed set | Таблица definition/option/product applicability invariants | Color/size/material обрабатываются одинаковым механизмом |
| Как обеспечить combination identity | Два конкурирующих save сценария и выбранный DB constraint | Нельзя создать duplicate variant или выбрать два values одной axis |
| Как меняются используемые options | Lifecycle сценарий update/retire/delete | Старый заказ читается без обращения к текущему dictionary |
| Как отражать несколько складов | Location/stock item/reservation model | Последняя единица не продаётся дважды; transfer не создаёт stock из ничего |
| Как считать деньги | Money/quote/allocation model | Currency, rounding, taxes/discounts и snapshots формально определены |
| Как отделить host/UI | Один import command и два presentation adapters | Core работает без Livewire/Filament/Auth::user() |
| Какая часть принадлежит plugin | Реальный custom property type + promotion plugin | Нет записи в чужие таблицы и ломания mandatory transitions |

**Граница материала.** Исследование достаточно для обсуждения структуры и набора инвариантов Commerz, но не заменяет runtime audit Unbox. Не измерялись реальные объёмы каталога, SQL планы, миграции на production DB, auth exploitability, экономическая корректность заказов и поведение installed LaraCart internals. Эти границы сохранены явно, чтобы следующее ревью не приняло статические выводы за успешно пройденные проверки.
