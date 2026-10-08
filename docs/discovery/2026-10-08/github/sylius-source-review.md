# Sylius: независимые состояния, promotion processing и жизненный цикл stock

Дата анализа: 2026-10-08. Репозиторий есть в stars academici. Изучен default branch, commit `39313695548c709756ee9073bf309fa4ae89365a` от 2026-10-08T08:26:12+02:00. Лицензия кода: MIT ([файл лицензии](https://github.com/Sylius/Sylius/blob/39313695548c709756ee9073bf309fa4ae89365a/LICENSE)); это не оценка лицензий всех транзитивных зависимостей.

Уровень доказательства: статическое чтение исходников и миграций на указанном commit. Зависимости не устанавливались, миграции и тесты не запускались. Выводы о пригодности для Commerz — проектные гипотезы, а не решение о внедрении.

## Что подтверждено кодом

- Платформа PHP/Symfony/Doctrine, а не Laravel. Для Commerz особенно ценны доменные контракты, Component/Bundle разделение и explicit workflows; прямое подключение всего Sylius означает другую framework-модель.
- `AttributeValue` содержит text, boolean, integer, float, datetime, date и json. `getValue`/`setValue` выбирает storage method на основании attribute storage type. Type атрибута и физический storage type являются разными понятиями.
- `ProductAttributeValue` mapping связывает значение с product и attribute; имя таблицы `sylius_product_attribute_value`. localeCode находится в базовой модели атрибута.
- `ChannelPricing` имеет уникальную пару product_variant_id+channel_code, integer price/originalPrice/minimumPrice/lowestPriceBeforeDiscount и связи применённых catalog promotions.
- Inventory operator разделяет `hold`, `sell`, `cancel`, внутренние `release` и `giveBack`. Sell уменьшает и onHold, и onHand; перед этим проверяет достаточность обоих. Cancel выбирает возврат onHand либо освобождение onHold по payment state.
- Для order, order payment, order shipping, checkout, payment и shipment существуют отдельные workflow configurations. Прочитанный order workflow содержит cart/new/cancelled/fulfilled и явно допустимые transitions.
- PromotionProcessor сначала откатывает прежние promotions, затем рассматривает eligible exclusive promotions и завершает после применения одного; только если такого нет — применяет подходящие non-exclusive promotions.

## Что имеет смысл заимствовать

1. Раздельные автомат состояний заказа, оплаты и отгрузки: единый status плохо выражает частичную оплату/отгрузку.
2. Явное различение физического остатка и удержания; методы жизненного цикла говорят о смысле операции.
3. Пересчёт promotions как управляемый процесс с revert и exclusivity, а не накапливание процентов при каждом HTTP request.
4. Цена зависит от варианта и канала; история/минимальные значения цен могут быть отдельным аспектом модели.

## Ограничения и вопросы для Commerz

- Inventory operator меняет объекты в памяти. Его чтение не доказывает схему блокировок/транзакций persistence boundary. Нужен отдельный аудит, прежде чем утверждать устойчивость к oversell.
- Aggregate onHold не заменяет individual Reservation с order/TTL/release reason. Много складов и распределение по партиям не установлены этим обзором.
- Логика отмены по payment state — конкретная политика Sylius. Для нашего домена частично оплаченный/отгруженный/возвращённый заказ надо разобрать отдельно.
- Revert/apply помогает детерминизму пересчёта, но идемпотентность внешних эффектов этим не обеспечивается.
- Подключение Doctrine/Symfony-компонентов должно быть осознанным; для экосистемы Laravel вероятнее полезны концепции и сценарии, чем wholesale dependency.

## Следующая проверка

Сформулировать таблицу переходов для частичной оплаты, двух отгрузок и одного возврата; проверить deferred capture, cancelled unpaid order и повторный payment event. Для типов свойств — custom semantic type поверх decimal storage.


Проверенные исходники:

- [composer.json](https://github.com/Sylius/Sylius/blob/39313695548c709756ee9073bf309fa4ae89365a/composer.json)
- [src/Sylius/Component/Attribute/Model/AttributeValue.php](https://github.com/Sylius/Sylius/blob/39313695548c709756ee9073bf309fa4ae89365a/src/Sylius/Component/Attribute/Model/AttributeValue.php)
- [src/Sylius/Bundle/ProductBundle/Resources/config/doctrine/model/ProductAttributeValue.orm.xml](https://github.com/Sylius/Sylius/blob/39313695548c709756ee9073bf309fa4ae89365a/src/Sylius/Bundle/ProductBundle/Resources/config/doctrine/model/ProductAttributeValue.orm.xml)
- [src/Sylius/Bundle/CoreBundle/Resources/config/doctrine/model/ChannelPricing.orm.xml](https://github.com/Sylius/Sylius/blob/39313695548c709756ee9073bf309fa4ae89365a/src/Sylius/Bundle/CoreBundle/Resources/config/doctrine/model/ChannelPricing.orm.xml)
- [src/Sylius/Component/Core/Inventory/Operator/OrderInventoryOperator.php](https://github.com/Sylius/Sylius/blob/39313695548c709756ee9073bf309fa4ae89365a/src/Sylius/Component/Core/Inventory/Operator/OrderInventoryOperator.php)
- [src/Sylius/Component/Promotion/Processor/PromotionProcessor.php](https://github.com/Sylius/Sylius/blob/39313695548c709756ee9073bf309fa4ae89365a/src/Sylius/Component/Promotion/Processor/PromotionProcessor.php)
- [src/Sylius/Bundle/CoreBundle/Resources/config/app/workflow/sylius_order.yaml](https://github.com/Sylius/Sylius/blob/39313695548c709756ee9073bf309fa4ae89365a/src/Sylius/Bundle/CoreBundle/Resources/config/app/workflow/sylius_order.yaml)
- [src/Sylius/Bundle/CoreBundle/Resources/config/app/workflow/sylius_order_payment.yaml](https://github.com/Sylius/Sylius/blob/39313695548c709756ee9073bf309fa4ae89365a/src/Sylius/Bundle/CoreBundle/Resources/config/app/workflow/sylius_order_payment.yaml)
- [src/Sylius/Bundle/CoreBundle/Resources/config/app/workflow/sylius_order_shipping.yaml](https://github.com/Sylius/Sylius/blob/39313695548c709756ee9073bf309fa4ae89365a/src/Sylius/Bundle/CoreBundle/Resources/config/app/workflow/sylius_order_shipping.yaml)
