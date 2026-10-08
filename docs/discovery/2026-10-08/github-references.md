# GitHub stars → референсы для Commerz

Снимок 2026-10-08: аккаунт **academici**, **715 уникальных starred repositories**. Из них **284 широких PHP/Laravel-кандидата** по языку, имени, описанию и topics. Шесть commerce-проектов прочитаны на уровне реального кода/схемы/расширений; ещё один проход по всем stars сформировал тематическую очередь. Это исследовательская база, не выбранный стек и не утверждённая схема БД.

Упомянутый пользователем «Bazaar» вероятнее всего — **[conedevelopment/bazar](https://github.com/conedevelopment/bazar)**: он есть в stars. Точного имени `bazaar` в выгрузке нет.

## Полнота и воспроизводимость

Команды чтения:

```bash
gh api user --jq '{login, name}'
gh api --paginate 'user/starred?per_page=100'
gh api --include 'user/starred?per_page=100&page=8'
gh api 'user/starred?per_page=100&page=9'
```

Установлен gh 2.45.0: `--slurp` не поддерживается; `--paginate` этой версии уже объединил ответы страниц в один JSON-массив. Проверены 715 записей и 715 уникальных repository ID. Отдельно получена страница 8: 15 записей, совпадающих с последними 15 основного массива; страница 9 пуста. При размере 100 это 7 полных страниц + последняя из 15. HTTP evidence и машинный manifest сохранены. Это полный доступный списку API снимок на момент запроса; изменения stars во время/после сбора не отслеживаются.

- [Все 715 stars: читаемый каталог](github/all-starred-catalog.md).
- [Полные repository metadata JSON](github/starred-repositories.json).
- [Все 284 PHP/Laravel-кандидата](github/php-laravel-catalog.md) и [JSON](github/php-laravel-candidates.json).
- [Manifest сбора](github/collection-manifest.json), [HTTP последней страницы](github/pagination-last-page-http.txt), [пустая страница после конца](github/pagination-after-last-page.json).
- [SHA, даты commit и Composer requirements шести проектов](github/source-manifest.json).
- [Индекс 78 permalink на конкретные файлы/commit](github/source-file-index.json).

Исходники получены shallow clone в `/tmp/commerz-stars-*`. В проект не добавлялись vendor, рабочие приложения или чужие копии исходников. GitHub не изменялся. Зависимости не устанавливались и тесты сторонних проектов не выполнялись; проверено чтением кода, а не запуском.

## Шесть первичных референсов

| Проект | Для чего особенно полезен | Что реально прочитано | Ограничение переноса |
|---|---|---|---|
| [Lunar](github/lunar-source-review.md) | Laravel headless core, типы атрибутов, pipelines, заменяемые модели | JSON/JSONB атрибуты, FieldTypeManifest, AsAttributeData, ModelManifest, Purchasable, cart config, price/discount migrations | FQCN в значениях; JSON-поиск надо измерять; stock на variant не закрывает WMS |
| [Bazar](github/bazar-source-review.md) | Компактные контракты, Laravel drivers, модельные proxies | ServiceProvider, Cart/Gateway managers, Product/Variant, property values, inventory trait, coupons | Float для coupon; tracking зависит от ненулевого metadata quantity; надо доказать конкуренцию |
| [Vanilo](github/vanilo-source-review.md) | Независимые модули, promotions/coupons/rules/actions/adjustments | Providers/Concord, registry типов, свойства, price/stock migrations, promotion schemas | Строковые property values; SQL decimal и float API различаются; Concord добавляет собственную модель расширения |
| [Bagisto](github/bagisto-source-review.md) | Typed EAV, channel/locale, inventory sources, read projections | Attribute value migrations+repository, inventory/ordered inventory, cart rules, module provider | Полное приложение; EAV-индексы/NULL scope uniqueness; runtime concurrency не аудирована |
| [Sylius](github/sylius-source-review.md) | Состояния order/payment/shipping, promotion processor, hold/sell/release | Attribute storage, ChannelPricing mapping, workflows, OrderInventoryOperator, PromotionProcessor | Symfony/Doctrine; оператор в памяти не доказывает транзакционную безопасность |
| [Shopper](github/shopper-source-review.md) | Короткий `sh_`, журнал stock, заменяемые allocation/reservation services | Inventory histories, HasStock, LockingStockReserver, PriorityStockAllocator, provider, reservation test | reserve уменьшает ledger stock; история допускает удаление; int quantity; нет ключа идемпотентности внутри reserve |

У всех шести на прочитанном commit код под MIT, подтверждено composer.json и LICENSE. Это не оценка всех зависимостей, внешних плагинов, брендинга или дополнительных коммерческих модулей.

## Тематическая очередь из stars

Следующие строки основаны на metadata GitHub и являются **кандидатами на следующий проход**: их код в этой итерации не аудирован. «Изучить» означает исследовательский вопрос, не обещание наличия/качества функции. Лицензия — значение GitHub API; `NOASSERTION`/`—` требует чтения конкретного LICENSE.

### Commerce, PIM, ERP и нестандартные продажи

| Репозиторий | Что исследовать | Лицензия API |
|---|---|---|
| [aimeos/aimeos-laravel](https://github.com/aimeos/aimeos-laravel) | Laravel-интеграция Aimeos; следующий шаг — перейти в owning core и проверить manager/decorator, product/list/property, multi-site scope. MIT wrapper нельзя считать лицензией всего стека. | MIT |
| [unopim/unopim](https://github.com/unopim/unopim) | PIM: семейства атрибутов, locale/channel, import/export, варианты, массовое редактирование и качество каталога. | MIT |
| [aureuserp/aureuserp](https://github.com/aureuserp/aureuserp) | ERP: purchase/sales/inventory, единицы измерения, связь склада с заказом; сравнить с commerce-only подходом. | MIT |
| [Blair2004/NexoPOS](https://github.com/Blair2004/NexoPOS) | POS и склад: движения, точки продаж, модульные расширения, офлайн/кассовые сценарии; API показывает GPL-3.0. | GPL-3.0 |
| [HiEventsDev/Hi.Events](https://github.com/HiEventsDev/Hi.Events) | Билеты: ограниченная вместимость, временные резервы, типы tickets; язык репозитория сам по себе не описывает весь backend. | NOASSERTION |
| [openclassify/openclassify](https://github.com/openclassify/openclassify) | Классифайды: модульные типы объявлений, поля, плагины; полезный контрпример товарам с физическим остатком. | — |
| [akaunting/akaunting](https://github.com/akaunting/akaunting) | Учёт: документы, валюты, граница бухгалтерии и commerce. Проверить лицензию отдельного модуля перед копированием. | NOASSERTION |
| [crater-invoice-inc/crater](https://github.com/crater-invoice-inc/crater) | Invoices: неизменяемые снимки строк/адресов/налогов, округление и документные статусы. | AGPL-3.0 |

### Купоны, ценообразование, подписки

| Репозиторий | Что исследовать | Лицензия API |
|---|---|---|
| [Safemood/discountify](https://github.com/Safemood/discountify) | Пользовательские условия скидок, схема регистрации conditions/actions и пересчёт. | MIT |
| [michael-rubel/laravel-couponables](https://github.com/michael-rubel/laravel-couponables) | Полиморфные промокоды: scope применимости, usage ledger, лимиты и конкурентное применение. | MIT |
| [mathiasonea/laravel-rulebook](https://github.com/mathiasonea/laravel-rulebook) | Версионируемые во времени правила и объяснение выбранного решения: кандидат для eligibility/price decision trace. | MIT |
| [lukepolo/laracart](https://github.com/lukepolo/laracart) | Небольшой cart API: строка, options, subtotal, discount/tax порядок. | MIT |
| [JackieDo/Laravel-Cart](https://github.com/JackieDo/Laravel-Cart) | Универсальная корзина: identity строк, слияние guest/customer cart, сериализация. | MIT |
| [revoltify/subscriptionify](https://github.com/revoltify/subscriptionify) | Feature entitlements и подписки: граница recurring product и access rights. | MIT |
| [Foysal50x/tashil](https://github.com/Foysal50x/tashil) | Usage/billing/feature limits: считать отдельным контекстом, проверить модель периодов и rollover. | MIT |
| [masterix21/laravel-entitlements](https://github.com/masterix21/laravel-entitlements) | Права и лимиты после продажи цифрового доступа. | MIT |
| [zaber-dev/laravel-quota](https://github.com/zaber-dev/laravel-quota) | Календарные периоды и счётчики квот: полезны для лимитированных benefits. | MIT |

### Платежи, деньги и единицы

| Репозиторий | Что исследовать | Лицензия API |
|---|---|---|
| [bavix/laravel-wallet](https://github.com/bavix/laravel-wallet) | Кошелёк: ledger, баланс, atomicity и rollback; не приравнивать wallet к системе эквайринга. | MIT |
| [HPWebdeveloper/laravel-pay-pocket](https://github.com/HPWebdeveloper/laravel-pay-pocket) | Альтернативная модель кошельков; сравнить точность и изоляцию конкурентных операций. | MIT |
| [thephpleague/omnipay](https://github.com/thephpleague/omnipay) | Framework-agnostic gateway API; проверить актуальные драйверы и асинхронную семантику платежей. | MIT |
| [shetabit/payment](https://github.com/shetabit/payment) | Laravel drivers, purchase/verify: сравнить контракт с capture/refund/webhooks. | MIT |
| [ken-de-nigerian/payzephyr](https://github.com/ken-de-nigerian/payzephyr) | Современный единый payments API: кандидат для driver ergonomics. | MIT |
| [square1-io/laravel-mpp](https://github.com/square1-io/laravel-mpp) | Machine payments: смежная идея, низкий приоритет для базового commerce. | MIT |
| [protonemedia/laravel-paddle](https://github.com/protonemedia/laravel-paddle) | Subscriptions/payment webhooks и связь внешнего billing provider с локальными сущностями. | MIT |
| [voronkovich/sberbank-acquiring-client](https://github.com/voronkovich/sberbank-acquiring-client) | Пример конкретного российского acquiring adapter, не core domain. | MIT |
| [appwilio/yakassa](https://github.com/appwilio/yakassa) | Локальная интеграция оплаты; актуальность API/чеков отдельно проверять в первичных документах. | MIT |
| [jordanbrauer/unit-converter](https://github.com/jordanbrauer/unit-converter) | Единицы измерения для массы/размеров/дробного количества; точность и dimension safety требуют проверки. | MIT |

### Модули, расширения, типы и инфраструктура

| Репозиторий | Что исследовать | Лицензия API |
|---|---|---|
| [InterNACHI/modular](https://github.com/InterNACHI/modular) | Границы Laravel modules и загрузка package resources. | MIT |
| [nWidart/laravel-modules](https://github.com/nWidart/laravel-modules) | Жизненный цикл модулей, service providers, миграции; сравнить с обычными Composer plugins. | MIT |
| [MiranSalehi/mksine](https://github.com/MiranSalehi/mksine) | Устанавливаемые plugins/themes/hooks: исследовать регистрацию, зависимости и порядок загрузки. | MIT |
| [fresns/fresns](https://github.com/fresns/fresns) | Расширяемая Laravel-платформа другого домена: lifecycle плагинов и контракты интеграции. | Apache-2.0 |
| [flarum/framework](https://github.com/flarum/framework) | Хороший смежный кандидат для extension API, стабильных hooks и совместимости версий. | MIT |
| [WendellAdriel/laravel-expressive](https://github.com/WendellAdriel/laravel-expressive) | Типизированные объекты поверх Eloquent: подход к сериализации и API value types. | MIT |
| [YorCreative/Laravel-Argonaut-DTO](https://github.com/YorCreative/Laravel-Argonaut-DTO) | DTO validation/casting для команд импорта и внешних контрактов. | MIT |
| [Astrotomic/laravel-translatable](https://github.com/Astrotomic/laravel-translatable) | Локализуемые значения и fallback-политика. | MIT |
| [justbetter/laravel-unique-values](https://github.com/justbetter/laravel-unique-values) | Генерация уникальных номеров под конкуренцией: order number/SKU/coupon code. | MIT |
| [PHP-CMSIG/search](https://github.com/PHP-CMSIG/search) | Search abstraction: кандидат для адаптеров поиска, не замена authoritative storage. | MIT |
| [hirethunk/verbs](https://github.com/hirethunk/verbs) | Event sourcing: сравнить для stock/ledger, не назначать на весь commerce автоматически. | MIT |
| [ecotoneframework/ecotone](https://github.com/ecotoneframework/ecotone) | Messages/CQRS/DDD и интеграционные гарантии; сложность нужно сопоставить масштабу пакета. | NOASSERTION |
| [nuwber/rabbitevents](https://github.com/nuwber/rabbitevents) | Интеграционные сообщения и брокер, проверить delivery semantics. | MIT |
| [prwnr/laravel-streamer](https://github.com/prwnr/laravel-streamer) | Redis Streams: альтернативный транспорт интеграционных событий. | — |
| [spatie/laravel-webhook-client](https://github.com/spatie/laravel-webhook-client) | Приём webhook; dedupe и state transition остаются доменными задачами. | MIT |
| [spatie/laravel-webhook-server](https://github.com/spatie/laravel-webhook-server) | Исходящие webhook и retries; исследовать вместе с transactional outbox. | MIT |
| [owen-it/laravel-auditing](https://github.com/owen-it/laravel-auditing) | Аудит изменения моделей; отличать от бухгалтерского или складского ledger. | MIT |
| [spatie/laravel-activitylog](https://github.com/spatie/laravel-activitylog) | Операционный audit trail для административных действий. | MIT |
| [spatie/laravel-medialibrary](https://github.com/spatie/laravel-medialibrary) | Медиа товаров: существующая инфраструктура вместо ещё одной реализации. | MIT |
| [tenancy/tenancy](https://github.com/tenancy/tenancy) | Разделение данных арендаторов; tenant/store/channel/location не считать синонимами. | MIT |

## Сравнение моделей атрибутов: исходные гипотезы

| Подход | Прочитанные примеры | Что даёт | Что должен решить Commerz |
|---|---|---|---|
| JSON/JSONB + registry типов | Lunar | Локальное чтение всех свойств, новые типы без новых SQL-колонок | Индексы диапазонов/фасетов, type version, migration значения, стабильный type key |
| EAV с typed columns в одной таблице | Bagisto; похожие value fields Sylius | Типизированные SQL-значения, scope на уровне записи | CHECK constraints, NULL uniqueness, сложные joins, multi-value, смена типа |
| String value + type transform | Vanilo; простые values Bazar/Shopper | Небольшая схема и удобное расширение PHP API | Числовая сортировка, точность, units, индексы, SQL validation |
| Hybrid: typed source + JSON document/search projection | Предложение для проверки, не утверждение о конкретном проекте | Разделяет authoritative values и быстрые чтения | Кто источник истины, атомарность обновления/lag, rebuild и reconciliation |

Важное различение: `type = measurement` — семантический тип; `decimal` — storage type; `kg` — единица; numeric range index — поисковое представление; UI widget — способ ввода. Их необязательно кодировать одной строкой `type`.

## Что уже можно поставить на проверку Claude Code

1. **Термины:** Product/SKU/Variant/Offer/StockItem; Store/Channel/Warehouse/Location/Tenant; где ответственность каждого понятия.
2. **Плагины:** достаточно ли service providers+contracts+registries или нужен отдельный plugin lifecycle. Проверить конфликт одинаковых type keys, совместимость, disabled plugin с сохранёнными данными, миграции и upgrade.
3. **Сток:** on_hand/reserved/available, indivisible vs fractional quantity, ledger vs cached balance, причины движений, TTL, idempotency, backorder, transfers и возвраты по местам хранения.
4. **Купоны:** campaign/rule/action/code/redemption/adjustment как разные сущности; последний coupon use в гонке; отмена заказа; per-customer limit; эксклюзивность и порядок.
5. **Цены:** currency+amount, precision/rounding, tax-inclusive/exclusive, quantity tiers, channel/customer group, действующий период; snapshots и распределение скидки между строками.
6. **Состояния:** order/payment/shipment/refund не сводить к одному status; допустимые переходы и их transactional effects.
7. **Свойства:** одинаковый атрибут в нескольких локалях/каналах, multi-value, inherited defaults, units, изменение типа, поиск по диапазону, уникальность в scope.
8. **Совместимость экосистемы:** префикс и model/table resolvers должны согласоваться с соседними пакетами; результаты этого файла сопоставить с отдельным ecosystem-аудитом.

Предлагаемый следующий source pass: **UnoPim + Aimeos core + AureusERP/NexoPOS + couponables/discountify + один payments ledger**. Уже выполненное чтение шести проектов не покрывает full WMS, бухгалтерию, налоговые юрисдикции, plugin uninstall или производительность на больших объёмах. Это конкретные открытые темы исследования.
