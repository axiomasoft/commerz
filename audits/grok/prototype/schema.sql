-- Commerz (Grok) — минимальный прототип PostgreSQL 17 для проверки ограничений и конкуренции.
-- Не миграция пакета: только подмножество таблиц, нужное для экспериментов.
-- UUIDv7 в пакете генерирует приложение; здесь gen_random_uuid() достаточно (PG17 без uuidv7()).
CREATE EXTENSION IF NOT EXISTS btree_gist;

DROP SCHEMA IF EXISTS proto CASCADE;
CREATE SCHEMA proto;
SET search_path = proto;

-- ── Каталог: товары, опции, варианты ─────────────────────────────────────────
CREATE TABLE cmz_products (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    handle      varchar(128) NOT NULL,
    attributes  jsonb NOT NULL DEFAULT '{}'::jsonb,
    CONSTRAINT cmz_products_handle_uq UNIQUE (handle),
    CONSTRAINT cmz_products_attributes_ck CHECK (jsonb_typeof(attributes) = 'object')
);

CREATE TABLE cmz_options (
    id    uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    code  varchar(64) NOT NULL,
    CONSTRAINT cmz_options_code_uq UNIQUE (code)
);

CREATE TABLE cmz_option_values (
    id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    option_id  uuid NOT NULL REFERENCES cmz_options (id),
    code       varchar(64) NOT NULL,
    CONSTRAINT cmz_option_values_option_id_code_uq UNIQUE (option_id, code),
    -- нужен для составного FK из cmz_variant_option_values: значение обязано принадлежать опции
    CONSTRAINT cmz_option_values_id_option_id_uq UNIQUE (id, option_id)
);

CREATE TABLE cmz_product_options (
    product_id uuid NOT NULL REFERENCES cmz_products (id) ON DELETE CASCADE,
    option_id  uuid NOT NULL REFERENCES cmz_options (id),
    position   smallint NOT NULL DEFAULT 0,
    CONSTRAINT cmz_product_options_pk PRIMARY KEY (product_id, option_id)
);

CREATE TABLE cmz_variants (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    product_id       uuid NOT NULL REFERENCES cmz_products (id),
    sku              varchar(64) NOT NULL,
    -- канонический отпечаток комбинации: отсортированные "option_code=value_code" через ';'
    option_signature varchar(512) NOT NULL DEFAULT '',
    CONSTRAINT cmz_variants_sku_uq UNIQUE (sku),
    CONSTRAINT cmz_variants_product_id_option_signature_uq UNIQUE (product_id, option_signature),
    CONSTRAINT cmz_variants_id_product_id_uq UNIQUE (id, product_id)
);

CREATE TABLE cmz_variant_option_values (
    variant_id      uuid NOT NULL,
    product_id      uuid NOT NULL,
    option_id       uuid NOT NULL,
    option_value_id uuid NOT NULL,
    CONSTRAINT cmz_variant_option_values_pk PRIMARY KEY (variant_id, option_id),
    -- вариант принадлежит товару
    CONSTRAINT cmz_variant_option_values_variant_fk FOREIGN KEY (variant_id, product_id)
        REFERENCES cmz_variants (id, product_id) ON DELETE CASCADE,
    -- опция подключена к этому товару
    CONSTRAINT cmz_variant_option_values_product_option_fk FOREIGN KEY (product_id, option_id)
        REFERENCES cmz_product_options (product_id, option_id),
    -- значение принадлежит этой опции
    CONSTRAINT cmz_variant_option_values_value_fk FOREIGN KEY (option_value_id, option_id)
        REFERENCES cmz_option_values (id, option_id)
);

-- ── Цены: запрет пересечения интервалов действия одной цены ────────────────────
CREATE TABLE cmz_prices (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    price_list_id uuid NOT NULL,
    variant_id    uuid NOT NULL REFERENCES cmz_variants (id),
    currency      char(3) NOT NULL,
    min_quantity  integer NOT NULL DEFAULT 1,
    amount_minor  bigint NOT NULL,
    valid_during  tstzrange NOT NULL DEFAULT tstzrange(NULL, NULL),
    CONSTRAINT cmz_prices_amount_ck CHECK (amount_minor >= 0),
    CONSTRAINT cmz_prices_min_quantity_ck CHECK (min_quantity >= 1),
    CONSTRAINT cmz_prices_currency_ck CHECK (currency ~ '^[A-Z]{3}$'),
    CONSTRAINT cmz_prices_no_overlap_ex EXCLUDE USING gist (
        price_list_id WITH =, variant_id WITH =, currency WITH =, min_quantity WITH =, valid_during WITH &&
    )
);

-- ── Склад: уровни (счётчики) + журнал + резервы ──────────────────────────────
CREATE TABLE cmz_stock_levels (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    stock_item_id    uuid NOT NULL,
    location_id      uuid NOT NULL,
    on_hand          bigint NOT NULL DEFAULT 0,   -- целое в базовой единице номенклатуры
    reserved         bigint NOT NULL DEFAULT 0,
    allows_negative  boolean NOT NULL DEFAULT false,
    version          bigint NOT NULL DEFAULT 0,
    CONSTRAINT cmz_stock_levels_stock_item_id_location_id_uq UNIQUE (stock_item_id, location_id),
    CONSTRAINT cmz_stock_levels_reserved_ck CHECK (reserved >= 0),
    CONSTRAINT cmz_stock_levels_on_hand_ck CHECK (allows_negative OR on_hand >= 0)
);

CREATE TABLE cmz_stock_movements (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    stock_level_id uuid NOT NULL REFERENCES cmz_stock_levels (id),
    kind           varchar(24) NOT NULL,
    delta_on_hand  bigint NOT NULL,
    source_type    varchar(32) NOT NULL,
    source_id      varchar(64) NOT NULL,
    occurred_at    timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT cmz_stock_movements_delta_ck CHECK (delta_on_hand <> 0),
    -- идемпотентность: один документ-источник двигает один уровень одним видом движения один раз
    CONSTRAINT cmz_stock_movements_source_uq UNIQUE (source_type, source_id, stock_level_id, kind)
);

CREATE TABLE cmz_stock_reservations (
    id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    stock_level_id uuid NOT NULL REFERENCES cmz_stock_levels (id),
    demand_type    varchar(32) NOT NULL,   -- cart | order | manual
    demand_id      varchar(64) NOT NULL,
    quantity       bigint NOT NULL,
    state          varchar(16) NOT NULL DEFAULT 'active',
    expires_at     timestamptz,
    CONSTRAINT cmz_stock_reservations_quantity_ck CHECK (quantity > 0),
    CONSTRAINT cmz_stock_reservations_state_ck CHECK (state IN ('active','consumed','released','expired')),
    -- одна активная бронь спроса на уровень: повтор команды не удваивает резерв
    CONSTRAINT cmz_stock_reservations_demand_uq UNIQUE (demand_type, demand_id, stock_level_id)
);
CREATE INDEX cmz_stock_reservations_expiry_ix ON cmz_stock_reservations (stock_level_id, expires_at)
    WHERE state = 'active';

-- Атомарный резерв: (1) ленивое освобождение просроченных броней этого уровня,
-- (2) условный UPDATE счётчика, (3) запись брони. Всё в транзакции вызывающего.
-- В пакете это PHP-обработчик (см. code-examples/Inventory/ReserveStockHandler.php); здесь — та же логика в SQL.
CREATE FUNCTION cmz_try_reserve(p_level uuid, p_demand text, p_qty bigint, p_ttl interval)
RETURNS boolean LANGUAGE plpgsql SET search_path = proto AS $$
DECLARE
    v_expired bigint;
    v_rows    integer;
BEGIN
    WITH expired AS (
        UPDATE cmz_stock_reservations
           SET state = 'expired'
         WHERE stock_level_id = p_level AND state = 'active' AND expires_at < now()
        RETURNING quantity
    )
    SELECT coalesce(sum(quantity), 0) INTO v_expired FROM expired;

    UPDATE cmz_stock_levels
       SET reserved = reserved - v_expired + p_qty,
           version  = version + 1
     WHERE id = p_level
       AND (allows_negative OR on_hand - (reserved - v_expired) >= p_qty);
    GET DIAGNOSTICS v_rows = ROW_COUNT;

    IF v_rows = 0 THEN
        -- не хватило: всё равно зафиксировать освобождение просроченных
        IF v_expired > 0 THEN
            UPDATE cmz_stock_levels SET reserved = reserved - v_expired, version = version + 1 WHERE id = p_level;
        END IF;
        RETURN false;
    END IF;

    INSERT INTO cmz_stock_reservations (stock_level_id, demand_type, demand_id, quantity, expires_at)
    VALUES (p_level, 'cart', p_demand, p_qty, now() + p_ttl);
    RETURN true;
END $$;

-- ── Акции и купоны: лимиты как счётчики с условным UPDATE ─────────────────────
CREATE TABLE cmz_promotions (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    code         varchar(64) NOT NULL,
    usage_limit  integer,
    usage_count  integer NOT NULL DEFAULT 0,
    budget_minor bigint,
    spent_minor  bigint NOT NULL DEFAULT 0,
    CONSTRAINT cmz_promotions_code_uq UNIQUE (code),
    CONSTRAINT cmz_promotions_usage_ck CHECK (usage_limit IS NULL OR usage_count <= usage_limit),
    CONSTRAINT cmz_promotions_budget_ck CHECK (budget_minor IS NULL OR spent_minor <= budget_minor)
);

CREATE TABLE cmz_coupons (
    id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    promotion_id       uuid NOT NULL REFERENCES cmz_promotions (id),
    code               varchar(64) NOT NULL,      -- нормализованный: UPPER, без пробелов/дефисов
    usage_limit        integer,
    per_customer_limit integer,
    usage_count        integer NOT NULL DEFAULT 0,
    CONSTRAINT cmz_coupons_code_uq UNIQUE (code),
    CONSTRAINT cmz_coupons_code_ck CHECK (code ~ '^[A-Z0-9]{3,64}$'),
    CONSTRAINT cmz_coupons_usage_ck CHECK (usage_limit IS NULL OR usage_count <= usage_limit)
);

CREATE TABLE cmz_coupon_redemptions (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    coupon_id    uuid NOT NULL REFERENCES cmz_coupons (id),
    order_id     uuid NOT NULL,
    customer_ref varchar(128),
    state        varchar(16) NOT NULL DEFAULT 'redeemed',
    CONSTRAINT cmz_coupon_redemptions_coupon_id_order_id_uq UNIQUE (coupon_id, order_id),
    CONSTRAINT cmz_coupon_redemptions_state_ck CHECK (state IN ('redeemed','reverted'))
);
CREATE INDEX cmz_coupon_redemptions_customer_ix ON cmz_coupon_redemptions (coupon_id, customer_ref)
    WHERE state = 'redeemed';

-- Погашение купона внутри транзакции оформления заказа.
CREATE FUNCTION cmz_try_redeem(p_code text, p_order uuid, p_customer text, p_discount bigint)
RETURNS boolean LANGUAGE plpgsql SET search_path = proto AS $$
DECLARE
    v_coupon cmz_coupons%ROWTYPE;
    v_used   integer;
BEGIN
    -- 1) блокируем строку купона: сериализует погашения одного кода (и per-customer подсчёт)
    SELECT * INTO v_coupon FROM cmz_coupons WHERE code = p_code FOR UPDATE;
    IF NOT FOUND THEN RETURN false; END IF;

    IF v_coupon.usage_limit IS NOT NULL AND v_coupon.usage_count >= v_coupon.usage_limit THEN
        RETURN false;
    END IF;

    IF v_coupon.per_customer_limit IS NOT NULL THEN
        SELECT count(*) INTO v_used FROM cmz_coupon_redemptions
         WHERE coupon_id = v_coupon.id AND customer_ref = p_customer AND state = 'redeemed';
        IF v_used >= v_coupon.per_customer_limit THEN RETURN false; END IF;
    END IF;

    -- 2) общий лимит акции и денежный бюджет — условный UPDATE (порядок блокировок: coupon → promotion)
    UPDATE cmz_promotions
       SET usage_count = usage_count + 1, spent_minor = spent_minor + p_discount
     WHERE id = v_coupon.promotion_id
       AND (usage_limit IS NULL OR usage_count < usage_limit)
       AND (budget_minor IS NULL OR spent_minor + p_discount <= budget_minor);
    IF NOT FOUND THEN RETURN false; END IF;

    UPDATE cmz_coupons SET usage_count = usage_count + 1 WHERE id = v_coupon.id;
    INSERT INTO cmz_coupon_redemptions (coupon_id, order_id, customer_ref) VALUES (v_coupon.id, p_order, p_customer);
    RETURN true;
END $$;
