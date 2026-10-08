# Bagisto: typed-column EAV, каналы и источники запасов

Дата анализа: 2026-10-08. Репозиторий есть в stars academici. Изучен default branch, commit `e32b99a97c51c803fa2a1561e3542c8043919256` от 2026-10-08T18:23:50+05:30. Лицензия кода: MIT ([файл лицензии](https://github.com/bagisto/bagisto/blob/e32b99a97c51c803fa2a1561e3542c8043919256/LICENSE)); это не оценка лицензий всех транзитивных зависимостей.

Уровень доказательства: статическое чтение исходников и миграций на указанном commit. Зависимости не устанавливались, миграции и тесты не запускались. Выводы о пригодности для Commerz — проектные гипотезы, а не решение о внедрении.

## Что подтверждено кодом

- Продуктовые подсистемы расположены в `packages/Webkul/*`: отдельные contracts, models, repositories, providers и database migrations. Root — полноценное приложение с Laravel и UI/integration dependencies, а не компактный независимый catalog package.
- `product_attribute_values` содержит product_id, attribute_id, nullable channel/locale и typed value columns: text, boolean, integer, decimal(12,4) под именем float_value, datetime, date, jsonb. Это одна EAV-таблица с несколькими колонками хранения.
- Исходная unique constraint строится на channel+locale+attribute_id+product_id. Migration 2024 года добавляет nullable unique_id и заполняет её конкатенацией составляющих. При оценке схемы необходимо читать эволюцию миграций, а не только create table.
- Repository имеет `getAttributeTypeColumnValues()`: обнуляет набор type columns и выставляет одну подходящую колонку при подготовке новых значений. Это application-side дисциплина; в прочитанной create migration нет CHECK «ровно одно значение нужного типа».
- Есть отдельные `inventory_sources` и привязка каналов к sources. `product_inventories` имеет product, inventory_source, vendor и qty с уникальностью тройки; `product_ordered_inventories` агрегирует qty по product+channel. Это разные представления physical source stock и ordered quantity.
- Имеются отдельные product_flat, inventory indices и price index модели/миграции. Это подтверждает применение read projections рядом с нормализованными данными, но порядок и гарантии их обновления здесь не аудировались.
- Cart rules отделяют условия (JSON), coupon policy, лимиты, временные окна, stop flag, action_type, discount_amount, discount_quantity, discount_step и shipping flags.
- Product module provider перечисляет модели; contracts/proxies и Concord дают механизм расширения типов и реализаций.

## Что имеет смысл заимствовать

Typed storage для поиска по диапазонам; явные locale/channel scopes; отделение инвентарного источника от канала продаж; нормализованная запись + специализированные read projections; отделение conditions/actions у promotions.

## Ограничения и вопросы для Commerz

- EAV требует стратегии индексов, joins для набора фильтров, сортировки и массового импорта. Перечень typed columns сам по себе не отвечает на эти вопросы.
- Nullable scope columns и SQL unique semantics требуют отдельного дизайна. unique_id через конкатенацию — изученный workaround, не автоматически рекомендуемая схема. Для Commerz сравнить ненулевой нормализованный scope key и БД-специфичные constraints.
- Смена attribute type требует migration значения и очистки старой колонки; некорректные direct writes могут обойти repository.
- product_inventories хранит integer qty. Дробные единицы, партии, серии, сроки годности, адресное хранение и owner не подтверждены изученными таблицами.
- Ordered inventory aggregate не даёт по одной этой таблице доказательства TTL каждого резерва, причин отмены и idempotency. Их следует задать явно в своей модели.
- Сильная связь с MySQL видна в root Composer через ext-pdo_mysql; совместимость всего приложения с другой СУБД не устанавливалась. Заимствование схемы нужно проверять на целевой СУБД отдельно.

## Следующая проверка

Сравнить EAV/JSONB/hybrid на одинаковом импорте и 5–10 фасетных фильтрах; проверить null-scopes, изменение типов и уникальные характеристики. Пройти inventory indexer, checkout и order cancellation при конкуренции в отдельном исследовании.


Проверенные исходники:

- [composer.json](https://github.com/bagisto/bagisto/blob/e32b99a97c51c803fa2a1561e3542c8043919256/composer.json)
- [packages/Webkul/Product/src/Database/Migrations/2018_07_27_070011_create_product_attribute_values_table.php](https://github.com/bagisto/bagisto/blob/e32b99a97c51c803fa2a1561e3542c8043919256/packages/Webkul/Product/src/Database/Migrations/2018_07_27_070011_create_product_attribute_values_table.php)
- [packages/Webkul/Product/src/Database/Migrations/2024_01_19_170350_add_unique_id_column_in_product_attribute_values_table.php](https://github.com/bagisto/bagisto/blob/e32b99a97c51c803fa2a1561e3542c8043919256/packages/Webkul/Product/src/Database/Migrations/2024_01_19_170350_add_unique_id_column_in_product_attribute_values_table.php)
- [packages/Webkul/Product/src/Repositories/ProductAttributeValueRepository.php](https://github.com/bagisto/bagisto/blob/e32b99a97c51c803fa2a1561e3542c8043919256/packages/Webkul/Product/src/Repositories/ProductAttributeValueRepository.php)
- [packages/Webkul/Product/src/Models/ProductAttributeValue.php](https://github.com/bagisto/bagisto/blob/e32b99a97c51c803fa2a1561e3542c8043919256/packages/Webkul/Product/src/Models/ProductAttributeValue.php)
- [packages/Webkul/Product/src/Database/Migrations/2018_07_27_113956_create_product_inventories_table.php](https://github.com/bagisto/bagisto/blob/e32b99a97c51c803fa2a1561e3542c8043919256/packages/Webkul/Product/src/Database/Migrations/2018_07_27_113956_create_product_inventories_table.php)
- [packages/Webkul/Product/src/Database/Migrations/2018_12_26_165327_create_product_ordered_inventories_table.php](https://github.com/bagisto/bagisto/blob/e32b99a97c51c803fa2a1561e3542c8043919256/packages/Webkul/Product/src/Database/Migrations/2018_12_26_165327_create_product_ordered_inventories_table.php)
- [packages/Webkul/Inventory/src/Database/Migrations/2018_07_23_110040_create_inventory_sources_table.php](https://github.com/bagisto/bagisto/blob/e32b99a97c51c803fa2a1561e3542c8043919256/packages/Webkul/Inventory/src/Database/Migrations/2018_07_23_110040_create_inventory_sources_table.php)
- [packages/Webkul/Core/src/Database/Migrations/2018_12_24_123812_create_channel_inventory_sources_table.php](https://github.com/bagisto/bagisto/blob/e32b99a97c51c803fa2a1561e3542c8043919256/packages/Webkul/Core/src/Database/Migrations/2018_12_24_123812_create_channel_inventory_sources_table.php)
- [packages/Webkul/CartRule/src/Database/Migrations/2019_05_13_024321_create_cart_rules_table.php](https://github.com/bagisto/bagisto/blob/e32b99a97c51c803fa2a1561e3542c8043919256/packages/Webkul/CartRule/src/Database/Migrations/2019_05_13_024321_create_cart_rules_table.php)
- [packages/Webkul/Product/src/Providers/ModuleServiceProvider.php](https://github.com/bagisto/bagisto/blob/e32b99a97c51c803fa2a1561e3542c8043919256/packages/Webkul/Product/src/Providers/ModuleServiceProvider.php)
