# Прототип: ограничения и конкуренция на PostgreSQL 17

Цель — проверить на живой БД те инварианты, на которых стоят решения в [database.md](../database.md),
а не оставлять их «кандидатами». Это подмножество таблиц, не миграции пакета.

- [schema.sql](schema.sql) — таблицы, ограничения, две функции (`cmz_try_reserve`, `cmz_try_redeem`).
  В пакете та же логика живёт в PHP-обработчиках ([ReserveStockHandler](../code-examples/Inventory/ReserveStockHandler.php),
  [RedeemCouponHandler](../code-examples/Coupons/RedeemCouponHandler.php)); SQL-функции здесь — только чтобы
  гонять конкуренцию через `pgbench` без PHP-стенда.
- [run.sh](run.sh) — воспроизведение (нужны `psql`, `pgbench`, пустая БД).

Окружение: PostgreSQL 17.11, локальный кластер, `READ COMMITTED` (дефолт), 32 клиента × 20 транзакций, 8 потоков.
Дата прогона: 2026-10-08.

## Результат

```text
== E1. Ограничения каталога и цен
OK    дубль комбинации осей: violates unique constraint "cmz_variants_product_id_option_signature_uq"
OK    значение чужой опции (size=red): violates foreign key constraint "cmz_variant_option_values_value_fk"
OK    опция не подключена к товару (material): violates foreign key constraint "cmz_variant_option_values_product_option_fk"
OK    пересечение интервалов цены: violates exclusion constraint "cmz_prices_no_overlap_ex"
OK    отрицательный резерв: violates check constraint "cmz_stock_levels_reserved_ck"
== E2. Последняя единица: 32 клиентов × 20 попыток зарезервировать 1 шт. при on_hand=50
      number of transactions actually processed: 640/640
      number of failed transactions: 0 (0.000%)
      reserved=50 active=50 on_hand=50
== E3. Контрпример: read-then-write без блокировки (как app-side reserved += q)
      reserved(counter)=20 reservations(rows)=640 on_hand=50  ← расхождение = потерянные обновления/овербукинг
== E4. Ленивое истечение: on_hand=10, reserved=10 просроченными бронями, новая бронь на 3
      try_reserve=t reserved=3 old_state=expired
== E5. Купон usage_limit=10: 32×20 конкурентных погашений разными заказами
      redemptions=10 usage_count=10
== E6. per_customer_limit=1: один покупатель, 32×20 попыток
      redemptions(customer:42)=1
== E7. Денежный бюджет акции 1000 (минор), скидка 300 за заказ, 3 кода без лимитов
      spent_minor=900 usage_count=3
== E8. sameVariant: товар B имеет red/S и blue/M — фильтр color=red AND size=m не должен его находить
      sameVariant(red,m) → tee
      наивный product-level(red,m) → hoodie,tee  ← ложное срабатывание hoodie
      JSONB-атрибут material=cotton (GIN @>) → hoodie
```

## Что это доказывает и что нет

| Эксперимент | Вывод | Граница вывода |
|---|---|---|
| E1 | Принадлежность варианта товару, значения — опции, опции — товару выражается **составными FK** без триггеров. Пересечение интервалов цены ловит `EXCLUDE USING gist` | Нужен `btree_gist`; это аргумент за PostgreSQL-only |
| E2 | Условный `UPDATE … WHERE on_hand - reserved >= q` + `CHECK (reserved >= 0)` не даёт овербукинга при 640 конкурентных попытках на 50 шт. Ни одной ошибки сериализации/дедлока: блокируется одна строка уровня | Одна позиция на транзакцию. Для нескольких строк корзины — сортировка `stock_level_id` перед UPDATE (см. ReserveStockHandler) |
| E3 | Read-then-write без блокировки (так делает, например, Medusa `inventory-module.ts:262–303`, если вызывающий не сериализует доступ) теряет обновления: счётчик 20, броней 640 при остатке 50 | Демонстрация класса ошибки, а не аудит Medusa в продакшене |
| E4 | Ленивое истечение внутри той же транзакции освобождает просроченные брони — корректность не зависит от частоты sweeper'а | Sweeper всё равно нужен для уровней без нового спроса (витринная доступность) |
| E5–E7 | Лимит кода, лимит на покупателя и **денежный бюджет акции, общий для нескольких кодов**, держатся одной блокировкой строки купона + условным UPDATE строки акции; порядок блокировок фиксирован (coupon → promotion) | Нет claim/hold-фазы до оплаты: лимит расходуется при размещении заказа и возвращается при отмене (см. promotions-and-coupons.md §6) |
| E8 | sameVariant-фильтр по опциям обязан работать на уровне варианта; product-level EXISTS даёт ложные срабатывания | JSONB `@>` с GIN годится для описательных атрибутов товара, не для осей |

Не проверено: нагрузка на миллионы строк, latency p95, партиционирование журнала, поведение под `REPEATABLE READ`,
сеть/реплики. Это следующий шаг при выборе v1.
