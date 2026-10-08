# Lunar: расширяемый Laravel commerce и типизированные JSON-атрибуты

Дата анализа: 2026-10-08. Репозиторий есть в stars academici. Изучен default branch, commit `82f6e4a64585ac5f11ca1fce4b6897be870e691a` от 2026-08-27T15:14:46+01:00. Лицензия кода: MIT ([файл лицензии](https://github.com/lunarphp/lunar/blob/82f6e4a64585ac5f11ca1fce4b6897be870e691a/LICENSE.md)); это не оценка лицензий всех транзитивных зависимостей.

Уровень доказательства: статическое чтение исходников и миграций на указанном commit. Зависимости не устанавливались, миграции и тесты не запускались. Выводы о пригодности для Commerz — проектные гипотезы, а не решение о внедрении.

## Что подтверждено кодом

- `packages/core` выделен из административной части. В корневом Composer-файле находится монорепозиторий; требования корня включают Filament, поэтому нельзя приравнивать их к минимальным требованиям `lunarphp/core` без отдельной проверки.
- Табличный префикс настраивается, по умолчанию `lunar_`; отдельно существуют connection, morph prefix и тип ID внешних users. Это хороший пример того, что настройка пакета затрагивает и таблицы, и polymorphic aliases, и чужую модель пользователя.
- Справочник attributes описывает тип, группу, handle, required, configuration и default. Значения хранятся в `products.attribute_data`, а более поздние миграции добавляют их variant и другим объектам. Для PostgreSQL миграция 2025 года переводит ряд JSON-колонок в JSONB.
- `AsAttributeData` сериализует каждое значение как `field_type` с PHP class name и `value`; при чтении проверяет существование класса и реализацию FieldType. `FieldTypeManifest` позволяет добавлять собственные типы: стандартные включают текст, переводимый текст, число, toggle, список, dropdown и файл.
- `ModelManifest::replace()` переопределяет соответствие интерфейс → модель, связывает DI, route model binding и morph map. Гибкость моделей требует согласования всех трёх механизмов.
- `Purchasable` описывает продаваемый объект независимо от ProductVariant: цены, налог, идентификатор, варианты, shippable, доступность и inventory.
- Расчёт корзины — конфигурируемый упорядоченный pipeline: строки → доставка → subtotal доставки → discounts → taxes → финальный расчёт. Actions и validators настраиваются отдельно.
- prices связываются polymorphically с priceable и содержат integer price, currency, customer_group и tier. Начальная migration вариантов хранит integer stock/backorder на variant.
- discounts содержит тип, coupon, временное окно, uses/max_uses, priority, stop и data. В этой модели купон исходно является полем discount, а не отдельной кампанией с многими кодами.

## Что имеет смысл заимствовать

1. Контракт продаваемого объекта и отдельный снимок строки заказа: ядро может работать с физическим SKU, услугой или собственным типом приложения.
2. Registry типов свойств и понятные этапы расчёта вместо неструктурированных callback в разных местах.
3. Явные настройки префиксов и внешней user-модели, согласованная подмена моделей.
4. Разделение определения атрибута, его значения и отображения в админке.

## Ограничения и проверяемые гипотезы

- Хранение PHP FQCN внутри JSON связывает данные с рефакторингом namespace. Для Commerz предпочтительно проверить стабильные type keys + version + registry, миграцию типа и поведение при отсутствующем плагине. Текущий cast Lunar пропускает неизвестный класс — это видимое поведение, не обязательно желаемая политика Commerz.
- Гибкость JSON не доказывает скорость фасетного поиска, сортировки по числам и проверки уникальности. Потребуются нагрузочные пробы и отдельно определённые индексы/проекции.
- Простое stock/backorder на варианте не является доказательством полноценного WMS с партиями, ячейками и резервами с TTL. Эти требования нельзя объявить закрытыми найденной migration.
- Integer price полезен для дискретных денежных единиц, но размер integer, допустимый диапазон и правила округления нужно выбирать явно; сам факт integer не решает мультивалютность.
- Настраиваемый pipeline способен нарушить порядок скидок и налогов. Для Commerz контракт расширения должен фиксировать вход, выход, порядок и инварианты пересчёта.

## Следующая проверка

Прототипировать один тип «измерение с единицей», запрос с диапазоном и сортировкой, смену PHP-класса типа без потери данных, внешнюю UUID user-модель и повторный пересчёт корзины. Отдельно пройти discount usage counters и checkout под конкурентными запросами.


Проверенные исходники:

- [composer.json](https://github.com/lunarphp/lunar/blob/82f6e4a64585ac5f11ca1fce4b6897be870e691a/composer.json)
- [packages/core/config/database.php](https://github.com/lunarphp/lunar/blob/82f6e4a64585ac5f11ca1fce4b6897be870e691a/packages/core/config/database.php)
- [packages/core/database/migrations/2021_07_29_100005_create_attributes_table.php](https://github.com/lunarphp/lunar/blob/82f6e4a64585ac5f11ca1fce4b6897be870e691a/packages/core/database/migrations/2021_07_29_100005_create_attributes_table.php)
- [packages/core/database/migrations/2021_07_29_100020_create_products_table.php](https://github.com/lunarphp/lunar/blob/82f6e4a64585ac5f11ca1fce4b6897be870e691a/packages/core/database/migrations/2021_07_29_100020_create_products_table.php)
- [packages/core/database/migrations/2021_07_29_100030_create_product_variants_table.php](https://github.com/lunarphp/lunar/blob/82f6e4a64585ac5f11ca1fce4b6897be870e691a/packages/core/database/migrations/2021_07_29_100030_create_product_variants_table.php)
- [packages/core/database/migrations/2021_07_29_100050_create_prices_table.php](https://github.com/lunarphp/lunar/blob/82f6e4a64585ac5f11ca1fce4b6897be870e691a/packages/core/database/migrations/2021_07_29_100050_create_prices_table.php)
- [packages/core/database/migrations/2025_08_14_164000_switch_to_jsonb.php](https://github.com/lunarphp/lunar/blob/82f6e4a64585ac5f11ca1fce4b6897be870e691a/packages/core/database/migrations/2025_08_14_164000_switch_to_jsonb.php)
- [packages/core/src/Base/Casts/AsAttributeData.php](https://github.com/lunarphp/lunar/blob/82f6e4a64585ac5f11ca1fce4b6897be870e691a/packages/core/src/Base/Casts/AsAttributeData.php)
- [packages/core/src/Base/FieldTypeManifest.php](https://github.com/lunarphp/lunar/blob/82f6e4a64585ac5f11ca1fce4b6897be870e691a/packages/core/src/Base/FieldTypeManifest.php)
- [packages/core/src/Base/ModelManifest.php](https://github.com/lunarphp/lunar/blob/82f6e4a64585ac5f11ca1fce4b6897be870e691a/packages/core/src/Base/ModelManifest.php)
- [packages/core/src/Base/Purchasable.php](https://github.com/lunarphp/lunar/blob/82f6e4a64585ac5f11ca1fce4b6897be870e691a/packages/core/src/Base/Purchasable.php)
- [packages/core/config/cart.php](https://github.com/lunarphp/lunar/blob/82f6e4a64585ac5f11ca1fce4b6897be870e691a/packages/core/config/cart.php)
- [packages/core/database/migrations/2022_11_18_100000_create_discounts_table.php](https://github.com/lunarphp/lunar/blob/82f6e4a64585ac5f11ca1fce4b6897be870e691a/packages/core/database/migrations/2022_11_18_100000_create_discounts_table.php)
