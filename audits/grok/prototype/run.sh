#!/usr/bin/env bash
# Воспроизведение: локальный PostgreSQL 17 + pgbench. Пример запуска собственного кластера:
#   initdb -D /tmp/cmzpg -U grok --auth=trust && pg_ctl -D /tmp/cmzpg -o "-p 55499 -k /tmp" start
#   createdb -h /tmp -p 55499 -U grok cmz && PGHOST=/tmp PGPORT=55499 PGUSER=grok PGDATABASE=cmz ./run.sh
set -euo pipefail
cd "$(dirname "$0")"
export PGOPTIONS="-c client_min_messages=warning"
PSQL="psql -X -q -v ON_ERROR_STOP=1"
CLIENTS=${CLIENTS:-32}
TX=${TX:-20}

$PSQL -f schema.sql >/dev/null
q() { $PSQL -At -c "SET search_path=proto; $1"; }
expect_error() { # $1 = name, $2 = sql
  if out=$($PSQL -c "SET search_path=proto; $2" 2>&1); then echo "FAIL  $1: принято, ожидалась ошибка"; exit 1;
  else echo "OK    $1: $(echo "$out" | grep -o 'violates [a-z ]*constraint "[a-z_]*"' | head -1)"; fi
}

echo "== E1. Ограничения каталога и цен"
q "INSERT INTO cmz_products(id, handle) VALUES ('00000000-0000-0000-0000-000000000001','tee');
   INSERT INTO cmz_options(id, code) VALUES ('00000000-0000-0000-0000-0000000000a1','color'),('00000000-0000-0000-0000-0000000000a2','size'),('00000000-0000-0000-0000-0000000000a3','material');
   INSERT INTO cmz_option_values(id, option_id, code) VALUES
     ('00000000-0000-0000-0000-0000000000b1','00000000-0000-0000-0000-0000000000a1','red'),
     ('00000000-0000-0000-0000-0000000000b2','00000000-0000-0000-0000-0000000000a2','m'),
     ('00000000-0000-0000-0000-0000000000b3','00000000-0000-0000-0000-0000000000a3','cotton');
   INSERT INTO cmz_product_options(product_id, option_id) VALUES
     ('00000000-0000-0000-0000-000000000001','00000000-0000-0000-0000-0000000000a1'),
     ('00000000-0000-0000-0000-000000000001','00000000-0000-0000-0000-0000000000a2');
   INSERT INTO cmz_variants(id, product_id, sku, option_signature) VALUES
     ('00000000-0000-0000-0000-0000000000c1','00000000-0000-0000-0000-000000000001','TEE-RED-M','color=red;size=m');" >/dev/null
expect_error "дубль комбинации осей" \
  "INSERT INTO cmz_variants(product_id, sku, option_signature) VALUES ('00000000-0000-0000-0000-000000000001','TEE-RED-M-2','color=red;size=m')"
expect_error "значение чужой опции (size=red)" \
  "INSERT INTO cmz_variant_option_values VALUES ('00000000-0000-0000-0000-0000000000c1','00000000-0000-0000-0000-000000000001','00000000-0000-0000-0000-0000000000a2','00000000-0000-0000-0000-0000000000b1')"
expect_error "опция не подключена к товару (material)" \
  "INSERT INTO cmz_variant_option_values VALUES ('00000000-0000-0000-0000-0000000000c1','00000000-0000-0000-0000-000000000001','00000000-0000-0000-0000-0000000000a3','00000000-0000-0000-0000-0000000000b3')"
q "INSERT INTO cmz_prices(price_list_id, variant_id, currency, amount_minor, valid_during) VALUES
   ('00000000-0000-0000-0000-0000000000d1','00000000-0000-0000-0000-0000000000c1','RUB',199000, tstzrange('2026-10-01','2026-11-01'))" >/dev/null
expect_error "пересечение интервалов цены" \
  "INSERT INTO cmz_prices(price_list_id, variant_id, currency, amount_minor, valid_during) VALUES ('00000000-0000-0000-0000-0000000000d1','00000000-0000-0000-0000-0000000000c1','RUB',149000, tstzrange('2026-10-15','2026-12-01'))"
expect_error "отрицательный резерв" \
  "INSERT INTO cmz_stock_levels(stock_item_id, location_id, on_hand, reserved) VALUES (gen_random_uuid(), gen_random_uuid(), 5, -1)"

echo "== E2. Последняя единица: ${CLIENTS} клиентов × ${TX} попыток зарезервировать 1 шт. при on_hand=50"
LEVEL=$(q "INSERT INTO cmz_stock_levels(stock_item_id, location_id, on_hand) VALUES (gen_random_uuid(), gen_random_uuid(), 50) RETURNING id")
cat > /tmp/cmz_reserve.sql <<EOF
SELECT proto.cmz_try_reserve('$LEVEL'::uuid, md5(random()::text || clock_timestamp()::text), 1, interval '15 minutes');
EOF
pgbench -n -c "$CLIENTS" -j 8 -t "$TX" -f /tmp/cmz_reserve.sql 2>&1 | grep -E 'number of (transactions actually processed|failed)' | sed 's/^/      /'
echo "      reserved=$(q "SELECT reserved FROM cmz_stock_levels WHERE id='$LEVEL'") active=$(q "SELECT count(*) FROM cmz_stock_reservations WHERE stock_level_id='$LEVEL' AND state='active'") on_hand=50"

echo "== E3. Контрпример: read-then-write без блокировки (как app-side reserved += q)"
LEVEL2=$(q "INSERT INTO cmz_stock_levels(stock_item_id, location_id, on_hand) VALUES (gen_random_uuid(), gen_random_uuid(), 50) RETURNING id")
cat > /tmp/cmz_naive.sql <<EOF
\set r random(1, 1000000000)
BEGIN;
SELECT on_hand - reserved AS free, reserved AS cur FROM proto.cmz_stock_levels WHERE id = '$LEVEL2' \gset
SELECT pg_sleep(0.002);
UPDATE proto.cmz_stock_levels SET reserved = :cur + 1 WHERE id = '$LEVEL2' AND :free >= 1;
INSERT INTO proto.cmz_stock_reservations(stock_level_id, demand_type, demand_id, quantity) SELECT '$LEVEL2', 'cart', :r::text, 1 WHERE :free >= 1;
COMMIT;
EOF
pgbench -n -c "$CLIENTS" -j 8 -t "$TX" -f /tmp/cmz_naive.sql >/dev/null 2>&1 || true
echo "      reserved(counter)=$(q "SELECT reserved FROM cmz_stock_levels WHERE id='$LEVEL2'") reservations(rows)=$(q "SELECT count(*) FROM cmz_stock_reservations WHERE stock_level_id='$LEVEL2'") on_hand=50  ← расхождение = потерянные обновления/овербукинг"

echo "== E4. Ленивое истечение: on_hand=10, reserved=10 просроченными бронями, новая бронь на 3"
LEVEL3=$(q "INSERT INTO cmz_stock_levels(stock_item_id, location_id, on_hand, reserved) VALUES (gen_random_uuid(), gen_random_uuid(), 10, 10) RETURNING id")
q "INSERT INTO cmz_stock_reservations(stock_level_id, demand_type, demand_id, quantity, expires_at) VALUES ('$LEVEL3','cart','old', 10, now() - interval '1 minute')" >/dev/null
echo "      try_reserve=$(q "SELECT cmz_try_reserve('$LEVEL3', 'new', 3, interval '15 minutes')") reserved=$(q "SELECT reserved FROM cmz_stock_levels WHERE id='$LEVEL3'") old_state=$(q "SELECT state FROM cmz_stock_reservations WHERE demand_id='old'")"

echo "== E5. Купон usage_limit=10: ${CLIENTS}×${TX} конкурентных погашений разными заказами"
q "INSERT INTO cmz_promotions(id, code) VALUES ('00000000-0000-0000-0000-0000000000e1','AUTUMN');
   INSERT INTO cmz_coupons(promotion_id, code, usage_limit) VALUES ('00000000-0000-0000-0000-0000000000e1','AUTUMN10', 10);" >/dev/null
cat > /tmp/cmz_coupon.sql <<'EOF'
SELECT proto.cmz_try_redeem('AUTUMN10', gen_random_uuid(), md5(random()::text), 100);
EOF
pgbench -n -c "$CLIENTS" -j 8 -t "$TX" -f /tmp/cmz_coupon.sql >/dev/null 2>&1
echo "      redemptions=$(q "SELECT count(*) FROM cmz_coupon_redemptions r JOIN cmz_coupons c ON c.id=r.coupon_id WHERE c.code='AUTUMN10'") usage_count=$(q "SELECT usage_count FROM cmz_coupons WHERE code='AUTUMN10'")"

echo "== E6. per_customer_limit=1: один покупатель, ${CLIENTS}×${TX} попыток"
q "INSERT INTO cmz_coupons(promotion_id, code, per_customer_limit) VALUES ('00000000-0000-0000-0000-0000000000e1','WELCOME', 1)" >/dev/null
cat > /tmp/cmz_customer.sql <<'EOF'
SELECT proto.cmz_try_redeem('WELCOME', gen_random_uuid(), 'customer:42', 100);
EOF
pgbench -n -c "$CLIENTS" -j 8 -t "$TX" -f /tmp/cmz_customer.sql >/dev/null 2>&1
echo "      redemptions(customer:42)=$(q "SELECT count(*) FROM cmz_coupon_redemptions WHERE customer_ref='customer:42'")"

echo "== E7. Денежный бюджет акции 1000 (минор), скидка 300 за заказ, 3 кода без лимитов"
q "INSERT INTO cmz_promotions(id, code, budget_minor) VALUES ('00000000-0000-0000-0000-0000000000e2','BUDGET', 1000);
   INSERT INTO cmz_coupons(promotion_id, code) VALUES ('00000000-0000-0000-0000-0000000000e2','BUDGETA'),('00000000-0000-0000-0000-0000000000e2','BUDGETB'),('00000000-0000-0000-0000-0000000000e2','BUDGETC');" >/dev/null
cat > /tmp/cmz_budget.sql <<'EOF'
\set n random(0, 2)
SELECT proto.cmz_try_redeem((ARRAY['BUDGETA','BUDGETB','BUDGETC'])[:n + 1], gen_random_uuid(), md5(random()::text), 300);
EOF
pgbench -n -c "$CLIENTS" -j 8 -t "$TX" -f /tmp/cmz_budget.sql >/dev/null 2>&1
echo "      spent_minor=$(q "SELECT spent_minor FROM cmz_promotions WHERE code='BUDGET'") usage_count=$(q "SELECT usage_count FROM cmz_promotions WHERE code='BUDGET'")"

echo "== E8. sameVariant: товар B имеет red/S и blue/M — фильтр color=red AND size=m не должен его находить"
q "INSERT INTO cmz_option_values(id, option_id, code) VALUES
     ('00000000-0000-0000-0000-0000000000b4','00000000-0000-0000-0000-0000000000a1','blue'),
     ('00000000-0000-0000-0000-0000000000b5','00000000-0000-0000-0000-0000000000a2','s');
   INSERT INTO cmz_variant_option_values VALUES ('00000000-0000-0000-0000-0000000000c1','00000000-0000-0000-0000-000000000001','00000000-0000-0000-0000-0000000000a1','00000000-0000-0000-0000-0000000000b1'),
     ('00000000-0000-0000-0000-0000000000c1','00000000-0000-0000-0000-000000000001','00000000-0000-0000-0000-0000000000a2','00000000-0000-0000-0000-0000000000b2');
   INSERT INTO cmz_products(id, handle, attributes) VALUES ('00000000-0000-0000-0000-000000000002','hoodie','{\"material\":\"cotton\",\"weight_g\":450}');
   INSERT INTO cmz_product_options VALUES ('00000000-0000-0000-0000-000000000002','00000000-0000-0000-0000-0000000000a1',0),('00000000-0000-0000-0000-000000000002','00000000-0000-0000-0000-0000000000a2',1);
   INSERT INTO cmz_variants(id, product_id, sku, option_signature) VALUES
     ('00000000-0000-0000-0000-0000000000c2','00000000-0000-0000-0000-000000000002','HD-RED-S','color=red;size=s'),
     ('00000000-0000-0000-0000-0000000000c3','00000000-0000-0000-0000-000000000002','HD-BLUE-M','color=blue;size=m');
   INSERT INTO cmz_variant_option_values VALUES
     ('00000000-0000-0000-0000-0000000000c2','00000000-0000-0000-0000-000000000002','00000000-0000-0000-0000-0000000000a1','00000000-0000-0000-0000-0000000000b1'),
     ('00000000-0000-0000-0000-0000000000c2','00000000-0000-0000-0000-000000000002','00000000-0000-0000-0000-0000000000a2','00000000-0000-0000-0000-0000000000b5'),
     ('00000000-0000-0000-0000-0000000000c3','00000000-0000-0000-0000-000000000002','00000000-0000-0000-0000-0000000000a1','00000000-0000-0000-0000-0000000000b4'),
     ('00000000-0000-0000-0000-0000000000c3','00000000-0000-0000-0000-000000000002','00000000-0000-0000-0000-0000000000a2','00000000-0000-0000-0000-0000000000b2');" >/dev/null
echo "      sameVariant(red,m) → $(q "SELECT string_agg(DISTINCT p.handle, ',') FROM cmz_products p JOIN cmz_variants v ON v.product_id = p.id
  WHERE EXISTS (SELECT 1 FROM cmz_variant_option_values x WHERE x.variant_id = v.id AND x.option_value_id = '00000000-0000-0000-0000-0000000000b1')
    AND EXISTS (SELECT 1 FROM cmz_variant_option_values x WHERE x.variant_id = v.id AND x.option_value_id = '00000000-0000-0000-0000-0000000000b2')")"
echo "      наивный product-level(red,m) → $(q "SELECT string_agg(DISTINCT p.handle, ',') FROM cmz_products p
  WHERE EXISTS (SELECT 1 FROM cmz_variant_option_values x WHERE x.product_id = p.id AND x.option_value_id = '00000000-0000-0000-0000-0000000000b1')
    AND EXISTS (SELECT 1 FROM cmz_variant_option_values x WHERE x.product_id = p.id AND x.option_value_id = '00000000-0000-0000-0000-0000000000b2')")  ← ложное срабатывание hoodie"
echo "      JSONB-атрибут material=cotton (GIN @>) → $(q "SELECT string_agg(handle, ',') FROM cmz_products WHERE attributes @> '{\"material\":\"cotton\"}'")"
