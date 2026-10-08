# Shopper: короткий префикс, stock ledger и заменяемое распределение по складам

Дата анализа: 2026-10-08. Репозиторий есть в stars academici. Изучен default branch, commit `86da81306a7f3b0f981ccd196182fb15481dea6c` от 2026-09-25T12:37:03+02:00. Лицензия кода: MIT ([файл лицензии](https://github.com/shopperlabs/shopper/blob/86da81306a7f3b0f981ccd196182fb15481dea6c/LICENSE.md)); это не оценка лицензий всех транзитивных зависимостей.

Уровень доказательства: статическое чтение исходников и миграций на указанном commit. Зависимости не устанавливались, миграции и тесты не запускались. Выводы о пригодности для Commerz — проектные гипотезы, а не решение о внедрении.

## Что подтверждено кодом

- Монорепозиторий разделяет core, cart, shipping и другие пакеты; root включает Filament. Core uses Spatie package-tools.
- `core.php` задаёт `table_prefix = sh_`. Migration helper и `shopper_table()` используют общий механизм разрешения имён.
- Модели подменяются через config+contracts+container; morph map регистрирует стабильные aliases. StockResolver/Allocator/Reserver связываются независимо.
- `inventories` — места хранения с code, адресом и priority. `inventory_histories` хранит signed integer quantity, old_quantity, event, description, inventory, user и polymorphic stockable/reference.
- `HasStock::getStock` и `stockInventory` вычисляют остаток SUM(quantity) истории до выбранного момента. Есть пакетная загрузка остатков для коллекций и режим запрета lazy stock loading.
- PriorityStockAllocator сначала ищет один склад с достаточным остатком; если не находит — распределяет по нескольким. Контракт allocator не совпадает с операцией записи резерва.
- LockingStockReserver открывает DB transaction, берёт `lockForUpdate` на строку stockable, рассчитывает allocations и добавляет отрицательные записи истории. При разрешённом backorder допускает отрицательный stock на первом доступном складе.
- Если доступно меньше requested и backorder запрещён, reserve возвращает доступное число, но ничего не списывает. Это подтверждено кодом и прочитанным StockReservationTest. Число в результате нельзя трактовать как частично зарезервированное количество.
- Attributes имеют type и признаки searchable/filterable; attribute_values хранит string value. Prices хранят integer amount, compare_amount, cost_amount и currency.

## Что имеет смысл заимствовать

Три отдельных контракта: выбор складов → алгоритм распределения → атомарная запись. Полезны история движений с reference и автором, batch stock loading, общая функция таблиц с коротким префиксом, replaceable services и реальные сценарии stock tests.

## Ограничения и вопросы для Commerz

- Термин reserve реализован отрицательным движением того же stock ledger. Для полноценного WMS лучше отдельно определить on_hand, reserved, allocated, available и когда меняется каждое значение.
- Возврат int у reserve семантически неоднозначен: значение может означать недостаточную доступность без записи. Для Commerz стоит сравнить типизированный результат Reserved/Insufficient с явными requested/allocated/committed.
- История не является неизменяемым журналом по самому API: `HasStock::clearStock()` удаляет history. Для воспроизводимого складского учёта такой путь нужно заменить компенсирующим движением либо явно ограниченной сервисной операцией.
- Сам reserve-метод не содержит idempotency key или поиска уже созданного движения по reference. Повтор операции должен иметь отдельную защиту; оценка всего checkout здесь не проводилась.
- lock на stockable сериализует операции для товара, но все альтернативные писатели обязаны соблюдать тот же протокол. Наличие одного lock не доказывает глобальную корректность всей системы.
- SUM всей истории удобно для объяснимости, но стоимость запроса растёт. Сравнить ledger+balance projection и их сверку; не ограничиваться benchmark на десятках записей.
- Integer quantity в контрактах не подходит для дробных количеств без дополнительной модели масштаба/единиц.

## Следующая проверка

Сконструировать два конкурентных заказа на последний SKU, повтор reserve с тем же idempotency key, split по двум складам и частичный возврат. Зафиксировать требуемую семантику «reserve» до выбора физической схемы.


Проверенные исходники:

- [composer.json](https://github.com/shopperlabs/shopper/blob/86da81306a7f3b0f981ccd196182fb15481dea6c/composer.json)
- [packages/core/config/core.php](https://github.com/shopperlabs/shopper/blob/86da81306a7f3b0f981ccd196182fb15481dea6c/packages/core/config/core.php)
- [packages/core/src/CoreServiceProvider.php](https://github.com/shopperlabs/shopper/blob/86da81306a7f3b0f981ccd196182fb15481dea6c/packages/core/src/CoreServiceProvider.php)
- [packages/core/src/Contracts/InventoryResolver.php](https://github.com/shopperlabs/shopper/blob/86da81306a7f3b0f981ccd196182fb15481dea6c/packages/core/src/Contracts/InventoryResolver.php)
- [packages/core/src/Contracts/StockAllocator.php](https://github.com/shopperlabs/shopper/blob/86da81306a7f3b0f981ccd196182fb15481dea6c/packages/core/src/Contracts/StockAllocator.php)
- [packages/core/src/Contracts/StockReserver.php](https://github.com/shopperlabs/shopper/blob/86da81306a7f3b0f981ccd196182fb15481dea6c/packages/core/src/Contracts/StockReserver.php)
- [packages/core/src/Stock/PriorityStockAllocator.php](https://github.com/shopperlabs/shopper/blob/86da81306a7f3b0f981ccd196182fb15481dea6c/packages/core/src/Stock/PriorityStockAllocator.php)
- [packages/core/src/Stock/LockingStockReserver.php](https://github.com/shopperlabs/shopper/blob/86da81306a7f3b0f981ccd196182fb15481dea6c/packages/core/src/Stock/LockingStockReserver.php)
- [packages/core/src/Models/Traits/HasStock.php](https://github.com/shopperlabs/shopper/blob/86da81306a7f3b0f981ccd196182fb15481dea6c/packages/core/src/Models/Traits/HasStock.php)
- [packages/core/database/migrations/2020_00_02_000002_create_inventories_table.php](https://github.com/shopperlabs/shopper/blob/86da81306a7f3b0f981ccd196182fb15481dea6c/packages/core/database/migrations/2020_00_02_000002_create_inventories_table.php)
- [packages/core/database/migrations/2020_00_02_000007_create_attributes_table.php](https://github.com/shopperlabs/shopper/blob/86da81306a7f3b0f981ccd196182fb15481dea6c/packages/core/database/migrations/2020_00_02_000007_create_attributes_table.php)
- [packages/core/database/migrations/2024_12_09_012530_create_prices_table.php](https://github.com/shopperlabs/shopper/blob/86da81306a7f3b0f981ccd196182fb15481dea6c/packages/core/database/migrations/2024_12_09_012530_create_prices_table.php)
- [tests/Core/Workflows/Stock/StockReservationTest.php](https://github.com/shopperlabs/shopper/blob/86da81306a7f3b0f981ccd196182fb15481dea6c/tests/Core/Workflows/Stock/StockReservationTest.php)
