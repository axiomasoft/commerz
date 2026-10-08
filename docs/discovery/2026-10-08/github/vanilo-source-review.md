# Vanilo: модульная композиция, properties, promotions и adjustments

Дата анализа: 2026-10-08. Репозиторий есть в stars academici. Изучен default branch, commit `008296b7b1adea7c42d22ba331ad2117b46f5169` от 2026-09-25T12:39:30+03:00. Лицензия кода: MIT ([файл лицензии](https://github.com/vanilophp/framework/blob/008296b7b1adea7c42d22ba331ad2117b46f5169/LICENSE.md)); это не оценка лицензий всех транзитивных зависимостей.

Уровень доказательства: статическое чтение исходников и миграций на указанном commit. Зависимости не устанавливались, миграции и тесты не запускались. Выводы о пригодности для Commerz — проектные гипотезы, а не решение о внедрении.

## Что подтверждено кодом

- Монорепозиторий содержит модули Product, Properties, Cart, Order, Channel, Promotion, Adjustments и другие, с отдельными composer.json и providers. Foundation соединяет модели и сервисы модулей.
- Модульные providers основаны на Konekt Concord. Foundation регистрирует соответствие контракт → модель, route bindings и явный morph map.
- Properties определяет контракт `PropertyType::transformValue(string, ?array)`; реестр использует стабильные ключи text/boolean/integer/number и допускает свои типы. `property_values.value` в БД — string, рядом title, priority и settings JSON. Типизация происходит при трансформации, не в SQL storage type.
- Первоначальная product migration использует decimal(15,4) price, поздняя stock migration — decimal(15,4) stock. Stockable API при этом возвращает float. Десятичное хранение не гарантирует десятичную арифметику приложения.
- Promotion разделяет promotions, coupons, promotion_rules и promotion_actions. Rule/action имеют type и configuration JSON. Coupon принадлежит promotion и имеет usage_limit, per_customer_usage_limit, usage_count и expires_at.
- `PromotionRuleTypes` и `PromotionActionTypes` — отдельные registry, проверяющие контракт и создающие обработчик через container.
- Adjustments — отдельная запись стоимости/корректировки, polymorphically привязанная к cart/order/line, с type, adjuster, origin, data, amount decimal(15,4), is_locked и is_included. Более поздняя migration добавляет source morph fields.
- Имена прочитанных таблиц универсальные (`products`, `properties`, `adjustments`, `coupons`); этот конкретный подход нужно адаптировать к короткому префиксу экосистемы Commerz.

## Что имеет смысл заимствовать

1. Независимость promotion definition, coupon code, eligibility rule, action и применённого adjustment.
2. Композиция отдельных доменных модулей в Foundation, контракты интеграции и registry по стабильному ключу.
3. Явный признак included для уже включённого налога и locked для фиксированной коррекции.
4. Отдельные семантики master product/variant и продаваемого продукта — полезный материал для терминологии, не готовое решение Commerz.

## Ограничения и альтернативы

- Строковые property values удобны для новых типов, но SQL range query для numeric/date требует продуманной схемы хранения/проекции; каст на чтении эту задачу не решает.
- Статический registry требует аккуратности в долгоживущих процессах и между тестами. Для Commerz нужно явно определить момент регистрации и конфликт ключей.
- Конфигурация Concord и proxy-модели — собственная сложность. Если текущая экосистема уже предоставляет model resolver, следует сравнить его возможности до добавления ещё одного.
- Наличие usage counters не доказывает атомарность coupon redemption. При переносе модели нужен отдельный протокол reserve/redeem/release и уникальная операция использования.
- Stockable с float — пригодность для весовых товаров остаётся вопросом точности, единиц измерения и округления; это не реализованный многоуровневый WMS.

## Следующая проверка

Собрать один внешний promotion plugin без изменения core; повторить расчёт и проверить, что adjustments не дублируются. Пройти перенос cart → order с замороженными ценами и проверить отмену уже использованного купона.


Проверенные исходники:

- [composer.json](https://github.com/vanilophp/framework/blob/008296b7b1adea7c42d22ba331ad2117b46f5169/composer.json)
- [src/Properties/Contracts/PropertyType.php](https://github.com/vanilophp/framework/blob/008296b7b1adea7c42d22ba331ad2117b46f5169/src/Properties/Contracts/PropertyType.php)
- [src/Properties/PropertyTypes.php](https://github.com/vanilophp/framework/blob/008296b7b1adea7c42d22ba331ad2117b46f5169/src/Properties/PropertyTypes.php)
- [src/Properties/resources/database/migrations/2018_12_08_112321_create_property_values_table.php](https://github.com/vanilophp/framework/blob/008296b7b1adea7c42d22ba331ad2117b46f5169/src/Properties/resources/database/migrations/2018_12_08_112321_create_property_values_table.php)
- [src/Properties/Providers/ModuleServiceProvider.php](https://github.com/vanilophp/framework/blob/008296b7b1adea7c42d22ba331ad2117b46f5169/src/Properties/Providers/ModuleServiceProvider.php)
- [src/Foundation/Providers/ModuleServiceProvider.php](https://github.com/vanilophp/framework/blob/008296b7b1adea7c42d22ba331ad2117b46f5169/src/Foundation/Providers/ModuleServiceProvider.php)
- [src/Product/resources/database/migrations/2017_10_07_133259_create_products_table.php](https://github.com/vanilophp/framework/blob/008296b7b1adea7c42d22ba331ad2117b46f5169/src/Product/resources/database/migrations/2017_10_07_133259_create_products_table.php)
- [src/Product/resources/database/migrations/2019_04_08_000000_add_stock_field_to_products_table.php](https://github.com/vanilophp/framework/blob/008296b7b1adea7c42d22ba331ad2117b46f5169/src/Product/resources/database/migrations/2019_04_08_000000_add_stock_field_to_products_table.php)
- [src/Contracts/Stockable.php](https://github.com/vanilophp/framework/blob/008296b7b1adea7c42d22ba331ad2117b46f5169/src/Contracts/Stockable.php)
- [src/Promotion/PromotionRuleTypes.php](https://github.com/vanilophp/framework/blob/008296b7b1adea7c42d22ba331ad2117b46f5169/src/Promotion/PromotionRuleTypes.php)
- [src/Promotion/PromotionActionTypes.php](https://github.com/vanilophp/framework/blob/008296b7b1adea7c42d22ba331ad2117b46f5169/src/Promotion/PromotionActionTypes.php)
- [src/Adjustments/resources/database/migrations/2021_05_27_113135_create_adjustments_table.php](https://github.com/vanilophp/framework/blob/008296b7b1adea7c42d22ba331ad2117b46f5169/src/Adjustments/resources/database/migrations/2021_05_27_113135_create_adjustments_table.php)
- [src/Promotion/resources/database/migrations/2024_06_08_131453_create_promotions_table.php](https://github.com/vanilophp/framework/blob/008296b7b1adea7c42d22ba331ad2117b46f5169/src/Promotion/resources/database/migrations/2024_06_08_131453_create_promotions_table.php)
- [src/Promotion/resources/database/migrations/2024_06_08_164653_create_coupons_table.php](https://github.com/vanilophp/framework/blob/008296b7b1adea7c42d22ba331ad2117b46f5169/src/Promotion/resources/database/migrations/2024_06_08_164653_create_coupons_table.php)
- [src/Promotion/resources/database/migrations/2024_06_09_095853_create_promotion_rules_table.php](https://github.com/vanilophp/framework/blob/008296b7b1adea7c42d22ba331ad2117b46f5169/src/Promotion/resources/database/migrations/2024_06_09_095853_create_promotion_rules_table.php)
- [src/Promotion/resources/database/migrations/2024_06_10_175853_create_promotion_actions_table.php](https://github.com/vanilophp/framework/blob/008296b7b1adea7c42d22ba331ad2117b46f5169/src/Promotion/resources/database/migrations/2024_06_10_175853_create_promotion_actions_table.php)
