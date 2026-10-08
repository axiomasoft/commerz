# Внешние модели: Shopify, Adobe Commerce, Shopware, commercetools

Проверено 2026-10-08. Это исследовательские референсы, не список выбранных зависимостей. GitHub-звёзды и PHP-пакеты разобраны отдельно в [github-references.md](github-references.md). Perplexity — отдельный поток в [perplexity-research.md](perplexity-research.md).

## Как читать доказательства

- **Код**: прочитан конкретный файл на фиксированном commit; ссылка воспроизводима. Это не означает запуск приложения или проверку всех путей исполнения.
- **Документация API**: подтверждает внешний контракт платформы. Она не раскрывает физическую схему закрытой SaaS-платформы.
- **Вывод для Commerz**: собственная гипотеза, подлежащая проверке сценариями.

Снимки метаданных, SHA и SHA-256 прочитанных файлов: [external/source-manifest.json](external/source-manifest.json); сами восемь файлов сохранены в [external/sources/](external/sources/). Для Magento 2 рекурсивное дерево GitHub было усечено; выбранные файлы трёх проектов получены полностью по конкретным путям. Этот анализ не претендует на полный обход Magento.

## 1. Shopify: разделять каталог, учётную позицию и состояние количества

**Проверено.** `InventoryItem` содержит учётные сведения и связан с уровнями запасов; `InventoryLevel` соединяет позицию и место. В API `2026-07` у `InventoryItem` присутствует `variants`, а одиночный `variant` помечен deprecated. Также есть `duplicateSkuCount`. Поэтому нельзя выводить обязательную глобальную уникальность SKU или вечную связь 1:1 только из привычной картинки Product → Variant → InventoryItem. [InventoryItem](https://shopify.dev/docs/api/admin-graphql/2026-07/objects/InventoryItem), [InventoryLevel](https://shopify.dev/docs/api/admin-graphql/2026-07/objects/InventoryLevel).

**Вывод для Commerz.** Внутренний идентификатор учётной номенклатуры должен отличаться от внешнего артикула. Отдельная связь коммерческого варианта и потребляемой номенклатуры позволяет исследовать общие остатки, упаковки и комплекты. Это ещё не аргумент сразу разрешить произвольный граф составов: для него понадобятся правила циклов, единиц и версий рецептуры.

**Проверено.** Shopify различает доступное количество и другие состояния: физический остаток включает также committed, reserved, damaged, safety_stock, quality_control. `incoming` не следует автоматически прибавлять к физически имеющемуся товару. [Manage inventory quantities and states](https://shopify.dev/docs/apps/build/orders-fulfillment/inventory-management-apps/manage-quantities-states).

**Вывод для Commerz.** Одной колонки `stock` недостаточно для полного учёта склада. Но не надо переносить все имена Shopify: сначала определить непересекающиеся состояния собственного баланса, затем вычисляемую доступность. «Недоступен», «зарезервирован», «обещан заказу» и «уже отгружен» нельзя произвольно вычитать дважды.

**Проверено.** Для абсолютной установки остатков Shopify рекомендует вызывающую систему как владельца достоверного количества и проверку `compareQuantity`; отключение сравнения при конкурентных изменениях может приводить к ошибкам. [inventorySetQuantities](https://shopify.dev/docs/api/admin-graphql/2026-07/mutations/inventorySetQuantities).

**Вывод для Commerz.** Команды «прибавить движение» и «сверить с абсолютным остатком внешней системы» должны иметь разную семантику. Абсолютный импорт требует версии/среза источника и согласования с локальными продажами; idempotency key сам по себе потерянное обновление не предотвращает.

## 2. Shopify: определения свойств и явные области расширения

**Проверено.** Metafield имеет тип; поддерживаются скаляры, измерения, списки и ссылки. Тип ограничивает доступные проверки. Определения отделены от значений, поддерживаются имена и пространства имён. [Types](https://shopify.dev/docs/apps/build/metafields/list-of-data-types), [Validation options](https://shopify.dev/docs/apps/build/metafields/list-of-validation-options), [Definitions](https://shopify.dev/docs/apps/build/metafields/definitions).

**Вывод для Commerz.** Гибкость — это не просто `json metadata`. Для типа нужны семантика равенства, нормализация, проверки, сериализация и доступные фильтры. У определения нужны владелец, стабильный код, область применения и правила изменения. Настройки формы относятся к адаптеру UI.

**Замеченное расхождение документации.** Страница типов обсуждает изменение типа и несовместимые значения; руководство definitions содержит таблицу с `Type: No`. Это нельзя превращать в однозначное обещание миграции типов без уточнения конкретного API и способа объявления. Для Commerz исследуем собственный явный протокол изменения схемы, не копируем это поведение по одному абзацу.

**Проверено.** Discount Functions выдают кандидатов скидок классов product/order/shipping; правила комбинации задаются отдельно. [Discount Function API](https://shopify.dev/docs/api/functions/latest/discount), [DiscountCombinesWith](https://shopify.dev/docs/api/admin-graphql/latest/objects/discountcombineswith).

**Вывод для Commerz.** Полезно отделить вычисление кандидата акции от окончательного выбора и распределения скидки. Два плагина не должны независимо изменять итоговую сумму корзины и случайно зависеть от порядка регистрации.

## 3. Adobe Commerce / Magento: три разных механизма расширения

**Проверено.** Документация различает merchant-defined EAV/custom attributes и extension attributes для расширения контрактов. Interceptors позволяют before/after/around публичных методов, с ограничениями и зависимостью порядка от `sortOrder` и последовательности модулей. [EAV and extension attributes](https://developer.adobe.com/commerce/php/development/components/attributes), [Plugins](https://developer.adobe.com/commerce/php/development/components/plugins).

**Вывод для Commerz.** Не объединять три задачи: динамические товарные данные, расширение DTO и изменение бизнес-поведения. Метод-перехватчик уровня Magento слишком широк как начальное обещание небольшого пакета. Кандидат — перечисленные контрактом стратегии, типы и события. Возражение: специализированных контрактов может оказаться недостаточно; это проверяется двумя независимыми плагинами, а не обещанием «можно расширить всё».

**Проверено кодом.** В Magento 2 `catalog_product_entity` хранит базовую идентичность, а значения распределены по таблицам `*_datetime`, `*_decimal`, `*_int`, `*_text`, `*_varchar`; ключ значения включает entity, attribute, store. [Catalog schema, SHA f510578](https://github.com/magento/magento2/blob/f51057831fe197c1feb2fe493bc2005d99f5e5cf/app/code/Magento/Catalog/etc/db_schema.xml#L10).

**Вывод для Commerz.** Типизированный EAV — реальный проверяемый вариант. Его стоимость надо измерять на запросах по нескольким характеристикам, языкам и вариантам. Сам факт применения в Magento не доказывает превосходство над JSON + производным индексом.

## 4. Magento Inventory: физические источники, коммерческие пулы, компенсации

**Проверено кодом.** Есть отдельные `inventory_source`, `inventory_source_item`, `inventory_stock`, `inventory_source_stock_link`; у количества источника уникальна пара source + SKU, у связи — stock + source. Источники и коммерческие stocks не одна сущность. [Inventory schema, SHA dea64c0](https://github.com/magento/inventory/blob/dea64c026e225763579512c49a34d6ce3cc3199d/Inventory/etc/db_schema.xml).

**Проверено кодом.** `inventory_reservation` имеет stock, SKU, signed quantity и metadata; это не такая же таблица, как адресный физический остаток. Привязки каналов вынесены отдельно. [Reservation schema](https://github.com/magento/inventory/blob/dea64c026e225763579512c49a34d6ce3cc3199d/InventoryReservations/etc/db_schema.xml), [Sales channel schema](https://github.com/magento/inventory/blob/dea64c026e225763579512c49a34d6ce3cc3199d/InventorySales/etc/db_schema.xml).

**Проверено документацией.** Резервы уменьшают salable quantity на уровне stock; отгрузка и отмена приводят к компенсирующим записям. Физические списания происходят по источникам. У механизма есть операционные зависимости, включая обработку очереди. [Source selection and reservations](https://experienceleague.adobe.com/en/docs/commerce-admin/inventory/basics/selection-reservations), [Order status and reservations](https://experienceleague.adobe.com/en/docs/commerce-admin/inventory/basics/order-status).

**Вывод для Commerz.** Стоит сравнить адресный резерв и отложенное назначение склада. Критический контрпример: два пула используют один склад и каждый продаёт последнюю единицу. Отдельные блокировки строк пулов не защищают общий физический ресурс. Нужен общий владелец доступности, адресное резервирование либо явные разделённые квоты. Здесь концептуальный рисунок необходимо подкрепить конкурентным экспериментом.

**Ограничение анализа.** Из этих трёх XML нельзя вывести доказательство отсутствия oversell, атомарности checkout или корректности всех режимов Magento. Для этого нужны сервисы, границы транзакций и исполняемые сценарии соответствующей версии.

## 5. Magento SalesRule: купон не равен акции

**Проверено кодом.** `salesrule` хранит правило, условия, действия, приоритет и прекращение дальнейшей обработки. `salesrule_coupon` отдельно хранит код и лимиты, а usage по купону/покупателю и агрегаты выделены в другие таблицы. [SalesRule schema, SHA f510578](https://github.com/magento/magento2/blob/f51057831fe197c1feb2fe493bc2005d99f5e5cf/app/code/Magento/SalesRule/etc/db_schema.xml).

**Вывод для Commerz.** Полезны отдельные сущности Promotion, Coupon, Redemption и распределённая по строкам сумма скидки. Но наличие счётчика `times_used` не является доказательством безопасного конкурентного лимита. Новый дизайн должен объяснять reservation of redemption, повтор платежа, отмену, возврат и политику восстановления права на купон.

## 6. Shopware: свойства для фильтрации отличаются от опций варианта

**Проверено кодом.** `ProductDefinition` содержит разные связи `options` и `properties`, parent/children, наследуемые поля и переводы. `PropertyGroupDefinition` отделяет группу от её options и признака filterable. Это отдельная модель, не идентичная произвольному EAV. [ProductDefinition, SHA db7c8e5](https://github.com/shopware/shopware/blob/db7c8e5d7df5a3928625751c17a0db829096b161/src/Core/Content/Product/ProductDefinition.php#L276), [PropertyGroupDefinition](https://github.com/shopware/shopware/blob/db7c8e5d7df5a3928625751c17a0db829096b161/src/Core/Content/Property/PropertyGroupDefinition.php#L84), [PropertyGroupOptionDefinition](https://github.com/shopware/shopware/blob/db7c8e5d7df5a3928625751c17a0db829096b161/src/Core/Content/Property/Aggregate/PropertyGroupOption/PropertyGroupOptionDefinition.php#L74).

**Вывод для Commerz.** Разделять описательную характеристику, ось вариантов, персонализацию покупки и поле партии. Одинаковое имя «цвет» не делает эти роли взаимозаменяемыми. Не каждая характеристика должна размножать варианты декартовым произведением.

**Проверено документацией.** Rule Builder использует условия разных предметных областей, в том числе состава корзины и доставки. [Example rules](https://docs.shopware.com/en/shopware-6-en/tutorials-and-faq/rule-builder-example-rules).

**Вывод для Commerz.** Общий язык предикатов может быть полезен для акций, публикаций и доставки, но общая инфраструктура не должна стирать разницу контекстов: предикат, которому нужен адрес доставки, нельзя молча исполнять в поисковом каталоге.

## 7. commercetools: момент резервирования является политикой

**Проверено.** Документированы режимы None, TrackOnly, ReserveOnOrder и ReserveOnCart. Reservations имеют срок и состояние; освобождение после expiry может происходить позже самого времени истечения. Поля InventoryEntry после операций резервирования обновляются с задержкой, но документация отдельно указывает: резервирование опирается на внутреннюю актуальную доступность SKU, и задержка отражения результата не ослабляет гарантию резерва. [Inventory overview](https://docs.commercetools.com/api/inventory-overview#inventory-checks-and-consistency), [Reservations](https://docs.commercetools.com/api/projects/reservations), [Cart inventory](https://docs.commercetools.com/learning-model-your-product-catalog/inventory-modeling/cart-inventory).

**Вывод для Commerz.** Не записывать «любое добавление в корзину резервирует товар» как универсальное правило. Определить политику для корзины, заказа, оплаты, истечения и backorder. Строгую гарантию продажи последней единицы нужно формулировать отдельно от скорости обновления каталожной доступности.

## Сопоставление идей

| Вопрос | Полезный референс | Что исследовать в Commerz | Что не следует из источника |
|---|---|---|---|
| SKU и физический запас | Shopify InventoryItem | Независимая идентичность и mapping предложений | Что текст SKU глобально уникален |
| Типы характеристик | Shopify definitions; Magento EAV; Lunar | Definition, value, codec, query semantics | Что выбран единственный оптимальный storage |
| Варианты | Shopware properties/options | Роль оси отдельно от типа данных | Что любое свойство создаёт SKU |
| Резервы | Magento MSI; commercetools | Место обещания, expiry, compensation | Что все гарантии одинаковы между платформами |
| Купоны | Magento SalesRule; Shopify Functions | Отдельные правило, код, применение и денежные строки | Что counters достаточно для quota enforcement |
| Плагины | Magento interceptors; локальные compile registries | Ограниченные точки расширения и проверка совместимости | Что перехват любого метода безопасен для инвариантов |
| Импорт | Shopify CAS mutation | Версия источника и владельцы количества | Что idempotency автоматически разрешает конфликт двух источников |

## Что ещё не изучено достаточно

- Полные механизмы пересчёта цен и налогов Shopware / Magento, включая частичные возвраты.
- Реализация конкурентного checkout в каждом референсе; по одной схеме данных она не доказывается.
- Commercial-only функции Adobe/Shopware: их нельзя считать доступными в open source коде.
- Форматы PIM и обмена с внешними ERP — не принимать предпосылку аудита о конкретной 1С-интеграции за требование владельца.
- Реальные распределения размеров каталогов и нагрузок будущих потребителей Commerz.
