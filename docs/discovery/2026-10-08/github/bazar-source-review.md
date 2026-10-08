# Bazar: компактные контракты и драйверы; пределы metadata-модели

Дата анализа: 2026-10-08. Репозиторий есть в stars academici. Изучен default branch, commit `32bc97a593915ca40e1323239b6ffd9582aa2f5f` от 2026-03-12T10:04:35+01:00. Лицензия кода: MIT ([файл лицензии](https://github.com/conedevelopment/bazar/blob/32bc97a593915ca40e1323239b6ffd9582aa2f5f/LICENSE)); это не оценка лицензий всех транзитивных зависимостей.

Уровень доказательства: статическое чтение исходников и миграций на указанном commit. Зависимости не устанавливались, миграции и тесты не запускались. Выводы о пригодности для Commerz — проектные гипотезы, а не решение о внедрении.

## Что подтверждено кодом

- Это `conedevelopment/bazar`, вероятный пакет из устного «Bazaar». Точного совпадения `bazaar` среди stars не обнаружено. Основная зависимость Composer — `conedevelopment/root`; PHP в изученном commit — ^8.4 или ^8.5.
- Таблицы используют `bazar_`; имя таблицы фиксируется в migrations и моделях.
- `BazarServiceProvider` связывает интерфейсы моделей с реализациями через Laravel container. Модели используют `InteractsWithProxy` из Root, а отношения строятся через `getProxiedClass()`.
- Cart/Gateway/Shipping имеют managers. Прочитанные Cart и Gateway наследуют Laravel Manager: несколько built-in drivers и стандартный механизм пользовательских creators.
- Product и Variant разделены. Variant имеет fallback цены и имени к Product, переиспользует tax rates продукта. Buyable связывается с item polymorphically.
- `bazar_property_values` содержит property_id, name и nullable string value; polymorphic pivot связывает значение с buyable. Это строковое хранение, а не типизация значений на уровне БД.
- Inventory trait читает weight/dimensions/virtual/downloadable/quantity из metadata. Изменение quantity — чтение metadata → арифметика float → save; decrement обрезает отрицательные значения до нуля.
- `PaymentCaptured` вызывает RefreshInventory, который уменьшает quantity продаваемых объектов заказа.
- Coupon — отдельная модель с code, float value, fixed/percent type, rules JSON, active, stackable и expires_at. Лимит берётся из rules и проверяется count приложений; применение выполняет `syncWithoutDetaching`. DiscountRule и Discount также имеют отдельные таблицы.

## Что имеет смысл заимствовать

Небольшие интерфейсы buyable/checkoutable, простой Laravel Manager для интеграций, консистентные модельные proxies, понятный набор published config/views/stubs. Пакет полезен как пример компактного Laravel API и как сравнение с более тяжёлыми commerce framework.

## Что не следует переносить без пересмотра

- Денежная арифметика coupon использует float и round(..., 2). Это конкретная политика, которая не покрывает произвольную валютную точность и распределение скидки по строкам.
- В `tracksQuantity()` проверяется `!empty(value)`. Следствие чтения кода: quantity=0 трактуется как отсутствие tracking; `isAvailable()` для нетрекаемого объекта возвращает true. Это обнаруженный риск поведения, не результат запуска тестов. Для Commerz флаг tracking должен быть независим от количества.
- Read-modify-save количества внутри trait сам по себе не показывает защиты от двух конкурентных списаний. Перехват события PaymentCaptured сам по себе не показывает дедупликации webhook.
- Coupon validate/count и последующая запись в этом методе не являются одной атомарной проверкой лимита. Нельзя копировать это как доказанную гарантию последнего использования купона.
- Polymorphic pivots дают универсальность, но FK не сможет обеспечить существование произвольного buyable. Нужна явная политика удаления и проверки ссылок.

## Следующая проверка

Сценарии quantity=0, два последних купона одновременно, повтор PaymentCaptured, переименование модели, отсутствие Root. Bazar сравнивать как источник API-идей; большой warehouse subsystem проектировать по самостоятельным инвариантам.


Проверенные исходники:

- [composer.json](https://github.com/conedevelopment/bazar/blob/32bc97a593915ca40e1323239b6ffd9582aa2f5f/composer.json)
- [src/BazarServiceProvider.php](https://github.com/conedevelopment/bazar/blob/32bc97a593915ca40e1323239b6ffd9582aa2f5f/src/BazarServiceProvider.php)
- [src/Cart/Manager.php](https://github.com/conedevelopment/bazar/blob/32bc97a593915ca40e1323239b6ffd9582aa2f5f/src/Cart/Manager.php)
- [src/Gateway/Manager.php](https://github.com/conedevelopment/bazar/blob/32bc97a593915ca40e1323239b6ffd9582aa2f5f/src/Gateway/Manager.php)
- [src/Models/Variant.php](https://github.com/conedevelopment/bazar/blob/32bc97a593915ca40e1323239b6ffd9582aa2f5f/src/Models/Variant.php)
- [database/migrations/2023_03_01_202959_create_bazar_property_values_table.php](https://github.com/conedevelopment/bazar/blob/32bc97a593915ca40e1323239b6ffd9582aa2f5f/database/migrations/2023_03_01_202959_create_bazar_property_values_table.php)
- [src/Traits/InteractsWithInventory.php](https://github.com/conedevelopment/bazar/blob/32bc97a593915ca40e1323239b6ffd9582aa2f5f/src/Traits/InteractsWithInventory.php)
- [src/Listeners/RefreshInventory.php](https://github.com/conedevelopment/bazar/blob/32bc97a593915ca40e1323239b6ffd9582aa2f5f/src/Listeners/RefreshInventory.php)
- [database/migrations/2025_12_14_211655_create_bazar_coupons_table.php](https://github.com/conedevelopment/bazar/blob/32bc97a593915ca40e1323239b6ffd9582aa2f5f/database/migrations/2025_12_14_211655_create_bazar_coupons_table.php)
- [src/Models/Coupon.php](https://github.com/conedevelopment/bazar/blob/32bc97a593915ca40e1323239b6ffd9582aa2f5f/src/Models/Coupon.php)
