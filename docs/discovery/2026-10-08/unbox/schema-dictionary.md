# Unbox: словарь фактической схемы

Статическая реконструкция из tracked migration source и `$table` моделей. Это не dump живой БД: установки и последующие ручные DDL не проверялись. Все поля ниже сохраняют исходный Blueprint метод/аргументы; столбцы timestamps/softDeletes сокращены в записи migration call. FK delete behavior указан буквально, без предположений о конкретном драйвере.

## `buyer_order_customers`

Source: `Modules/Buyer/database/migrations/2025_10_21_132001_create_buyer_order_customers_table.php:18`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 19 |
| `user_id` | `$table->foreignId('user_id')->constrained((new User)->getTable());` | 20 |
| `first_name` | `$table->string('first_name');` | 21 |
| `middle_name` | `$table->string('middle_name')->nullable();` | 22 |
| `last_name` | `$table->string('last_name');` | 23 |
| `email` | `$table->string('email')->nullable();` | 24 |
| `phone` | `$table->string('phone')->nullable();` | 25 |
| `comment` | `$table->string('comment')->nullable();` | 26 |
| `timestamps()` | `$table->timestamps();` | 27 |
| `softDeletes()` | `$table->softDeletes();` | 28 |

## `buyer_orders`

Source: `Modules/Buyer/database/migrations/2025_10_21_132002_create_buyer_orders_table.php:21`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 22 |
| `user_id` | `$table->foreignId('user_id')->constrained((new User)->getTable());` | 23 |
| `shop_id` | `$table->foreignId('shop_id')->constrained((new Shop)->getTable());` | 24 |
| `customer_id` | `$table->foreignId('customer_id')->constrained((new Customer)->getTable());` | 25 |
| `delivery_address_id` | `$table->foreignId('delivery_address_id')->constrained((new Address)->getTable());` | 26 |
| `order_number` | `$table->string('order_number', 30)->index();` | 27 |
| `status` | `$table->string('status', 16)->index()->comment('Статус заказа');` | 28 |
| `payment_method` | `$table->string('payment_method', 16)->index()->comment('Метод оплаты');` | 29 |
| `payment_status` | `$table->string('payment_status', 16)->index()->comment('Статус платежа');` | 30 |
| `gift_card_amount` | `$table->float('gift_card_amount', 12, 2)->default(0);` | 31 |
| `total_price` | `$table->float('total_price', 12, 2)->default(0);` | 32 |
| `total_discount` | `$table->float('total_discount', 12, 2)->default(0);` | 33 |
| `total_tax` | `$table->float('total_tax', 12, 2)->default(0);` | 34 |
| `total_shipping` | `$table->float('total_shipping', 12, 2)->default(0);` | 35 |
| `total_shipping_discount` | `$table->float('total_shipping_discount', 12, 2)->default(0);` | 36 |
| `total_shipping_tax` | `$table->float('total_shipping_tax', 12, 2)->default(0);` | 37 |
| `tracking_number` | `$table->string('tracking_number', 30)->nullable()->index()->comment('Номер отслеживания');` | 38 |
| `pickup_date` | `$table->dateTime('pickup_date')->nullable();` | 39 |
| `comment` | `$table->text('comment')->nullable();` | 40 |
| `timestamps()` | `$table->timestamps();` | 41 |

## `buyer_order_items`

Source: `Modules/Buyer/database/migrations/2025_10_21_132419_create_buyer_order_items_table.php:20`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 21 |
| `order_id` | `$table->foreignId('order_id')->constrained((new Order)->getTable())->cascadeOnDelete();` | 22 |
| `product_id` | `$table->foreignId('product_id')->constrained((new Product)->getTable());` | 23 |
| `offer_id` | `$table->foreignId('offer_id')->constrained((new ProductOffer)->getTable());` | 24 |
| `offer_properties` | `$table->json('offer_properties')->nullable();` | 25 |
| `quantity` | `$table->integer('quantity')->default(0);` | 26 |
| `unit_price` | `$table->float('unit_price', 12, 2)->default(0);` | 27 |
| `total_price` | `$table->float('total_price', 12, 2)->default(0);` | 28 |
| `discount_amount` | `$table->float('discount_amount', 12, 2)->default(0);` | 29 |
| `tax_amount` | `$table->float('tax_amount', 12, 2)->default(0);` | 30 |
| `comment` | `$table->text('comment')->nullable();` | 31 |
| `timestamps()` | `$table->timestamps();` | 32 |

## `buyer_order_updates`

Source: `Modules/Buyer/database/migrations/2025_10_21_132614_create_buyer_order_updates_table.php:19`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 20 |
| `order_id` | `$table->foreignId('order_id')->constrained((new Order)->getTable())->cascadeOnDelete();` | 21 |
| `user_id` | `$table->foreignId('user_id')->constrained((new User)->getTable())->cascadeOnDelete()->nullable();` | 22 |
| `action` | `$table->string('action', 16);` | 23 |
| `data` | `$table->json('data')->nullable();` | 24 |
| `date_added` | `$table->dateTime('date_added')->default(now());` | 25 |

## `buyer_order_refund_requests`

Source: `Modules/Buyer/database/migrations/2025_10_21_134028_create_buyer_order_refund_requests_table.php:19`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 20 |
| `order_id` | `$table->foreignId('order_id')->constrained((new Order)->getTable())->cascadeOnDelete();` | 21 |
| `user_id` | `$table->foreignId('user_id')->constrained((new User)->getTable())->cascadeOnDelete();` | 22 |
| `status` | `$table->string('status', 16)->index()->comment('Статус запроса на возврат');` | 23 |
| `reason` | `$table->text('reason')->nullable()->comment('Причина возврата');` | 24 |
| `amount` | `$table->float('amount', 12, 2)->default(0);` | 25 |
| `shipping_cost` | `$table->float('shipping_cost', 12, 2)->default(0);` | 26 |
| `total_cost` | `$table->float('total_cost', 12, 2)->default(0);` | 27 |
| `total_discount` | `$table->float('total_discount', 12, 2)->default(0);` | 28 |
| `timestamps()` | `$table->timestamps();` | 29 |

## `cat_categories`

Source: `Modules/Catalog/database/migrations/2025_10_19_180840_create_cat_categories_table.php:18`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 19 |
| `suggested_by_id` | `$table->foreignId('suggested_by_id')->nullable()->constrained((new User)->getTable())->onDelete('cascade');` | 20 |
| `parent_id` | `$table->foreignId('parent_id')->nullable()->constrained((new Category)->getTable());` | 21 |
| `name` | `$table->string('name');` | 22 |
| `slug` | `$table->string('slug')->unique()->index();` | 23 |
| `description` | `$table->text('description')->nullable();` | 24 |
| `sort_order` | `$table->unsignedMediumInteger('sort_order')->default(500)->comment('Сортировка');` | 25 |
| `is_active` | `$table->boolean('is_active')->default(true)->comment('Активность');` | 26 |
| `verified_at` | `$table->date('verified_at')->nullable()->index()->comment('Дата подтверждения');` | 27 |
| `verified_by_id` | `$table->foreignId('verified_by_id')->nullable()->constrained((new User)->getTable())->onDelete('cascade');` | 28 |
| `timestamps()` | `$table->timestamps();` | 29 |
| `softDeletes()` | `$table->softDeletes();` | 30 |

## `cat_brands`

Source: `Modules/Catalog/database/migrations/2025_10_19_180850_create_cat_brands_table.php:14`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 15 |
| `name` | `$table->string('name');` | 16 |
| `slug` | `$table->string('slug')->unique()->index();` | 17 |
| `description` | `$table->text('description')->nullable();` | 18 |
| `sort_order` | `$table->unsignedMediumInteger('sort_order')->default(500)->comment('Сортировка');` | 19 |
| `timestamps()` | `$table->timestamps();` | 20 |
| `softDeletes()` | `$table->softDeletes();` | 21 |

## `cat_collections`

Source: `Modules/Catalog/database/migrations/2025_10_19_185324_create_cat_collections_table.php:18`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 19 |
| `shop_id` | `$table->foreignId('shop_id')->nullable()->constrained((new Shop)->getTable())->onDelete('cascade');` | 20 |
| `name` | `$table->string('name');` | 21 |
| `slug` | `$table->string('slug')->unique()->index();` | 22 |
| `description` | `$table->text('description')->nullable();` | 23 |
| `subdescription` | `$table->string('subdescription', 255)->nullable();` | 24 |
| `sort_order` | `$table->unsignedMediumInteger('sort_order')->default(500)->comment('Сортировка');` | 25 |
| `is_active` | `$table->boolean('is_active')->default(true)->comment('Активность');` | 26 |
| `is_main` | `$table->boolean('is_main')->default(false)->comment('Главная');` | 27 |
| `verified` | `$table->boolean('verified')->nullable()->comment('Подтверждена');` | 28 |
| `softDeletes()` | `$table->softDeletes();` | 29 |
| `timestamps()` | `$table->timestamps();` | 30 |

## `cat_properties`

Source: `Modules/Catalog/database/migrations/2025_10_19_185330_create_cat_properties_table.php:16`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 17 |
| `shop_id` | `$table->foreignId('shop_id')->nullable()->constrained((new Shop)->getTable())->nullOnDelete();` | 18 |
| `type` | `$table->string('type', 12)->default(PropertyType::Text->value)->comment('Тип свойства: [text, integer, decimal]');` | 19 |
| `control` | `$table->string('control', 16)->nullable()->comment('Контрол создания свойств: например colorpicker для строкового свойства');` | 20 |
| `unit` | `$table->string('unit', 12)->nullable()->comment('Единица измерения');` | 21 |
| `name` | `$table->string('name', 50)->comment('Название свойства');` | 22 |
| `code` | `$table->string('code', 60)->index()->nullable()->comment('Код свойства');` | 23 |
| `sort_order` | `$table->unsignedSmallInteger('sort_order')->default(1)->comment('Сортировка');` | 24 |

## `cat_products`

Source: `Modules/Catalog/database/migrations/2025_10_19_185331_create_cat_products_table.php:21`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 22 |
| `shop_id` | `$table->foreignId('shop_id')->constrained((new Shop)->getTable())->onDelete('cascade');` | 23 |
| `created_by_id` | `$table->foreignId('created_by_id')->constrained((new User)->getTable());` | 24 |
| `name` | `$table->string('name');` | 25 |
| `subtitle` | `$table->string('subtitle')->nullable();` | 26 |
| `slug` | `$table->string('slug')->unique()->index();` | 27 |
| `brand_id` | `$table->foreignId('brand_id')->nullable()->constrained((new Brand)->getTable())->nullOnDelete();` | 28 |
| `description` | `$table->text('description')->nullable();` | 29 |
| `content` | `$table->json('content')->nullable();` | 30 |
| `sort_order` | `$table->unsignedMediumInteger('sort_order')->default(500)->comment('Сортировка');` | 31 |
| `inventory_policy` | `$table->string('inventory_policy', 16)->comment('Политика учета количества');` | 32 |
| `price` | `$table->float('price', 12, 2)->nullable()->comment('Цена');` | 33 |
| `old_price` | `$table->float('old_price', 12, 2)->nullable()->comment('Старая цена');` | 34 |
| `base_weight` | `$table->unsignedMediumInteger('base_weight')->nullable()->comment('Базовый вес товара');` | 35 |
| `base_length` | `$table->unsignedSmallInteger('base_length')->nullable()->comment('Базовая длина (мм)');` | 36 |
| `base_width` | `$table->unsignedSmallInteger('base_width')->nullable()->comment('Базовая ширина (мм)');` | 37 |
| `base_height` | `$table->unsignedSmallInteger('base_height')->nullable()->comment('Базовая высота (мм)');` | 38 |
| `measure_ratio` | `$table->unsignedSmallInteger('measure_ratio')->default(1)->comment('Коэффициент единицы измерения');` | 39 |
| `condition` | `$table->string('condition', 10);` | 40 |
| `is_new` | `$table->boolean('is_new')->default(false)->comment('Новое');` | 41 |
| `is_sale` | `$table->boolean('is_sale')->default(false)->comment('Распродажа');` | 42 |
| `is_discount` | `$table->boolean('is_discount')->default(false)->comment('Скидка');` | 43 |
| `is_hit` | `$table->boolean('is_hit')->default(false)->comment('Хит');` | 44 |
| `is_active` | `$table->boolean('is_active')->default(true)->comment('Активность');` | 45 |
| `published_at` | `$table->date('published_at')->nullable()->index()->comment('Дата публикации');` | 46 |
| `status` | `$table->string('status', 16)->index()->default(Status::Draft->value)->comment('Статус');` | 47 |
| `softDeletes()` | `$table->softDeletes();` | 48 |
| `timestamps()` | `$table->timestamps();` | 49 |

## `cat_product_properties`

Source: `Modules/Catalog/database/migrations/2025_10_19_185332_create_cat_product_properties_table.php:19`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 20 |
| `product_id` | `$table->foreignId('product_id')->constrained((new Product)->getTable())->cascadeOnDelete();` | 21 |
| `property_id` | `$table->foreignId('property_id')->constrained((new Property)->getTable())->cascadeOnDelete();` | 22 |
| `available_codes` | `$table->json('available_codes')->nullable();` | 23 |
| `unique()` | `$table->unique(['product_id', 'property_id']);` | 24 |

## `cat_property_values`

Source: `Modules/Catalog/database/migrations/2025_10_19_185334_create_cat_property_values_table.php:18`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 19 |
| `property_id` | `$table->foreignId('property_id')->constrained((new Property)->getTable())->cascadeOnDelete();` | 20 |
| `label` | `$table->string('label', 32)->nullable();` | 21 |
| `code` | `$table->string('code', 32)->nullable()->index();` | 22 |
| `value_text` | `$table->string('value_text')->nullable()->index();` | 23 |
| `value_integer` | `$table->bigInteger('value_integer')->index()->nullable();` | 24 |
| `value_decimal` | `$table->decimal('value_decimal', 12, 4)->index()->nullable();` | 25 |

## `cat_product_categories`

Source: `Modules/Catalog/database/migrations/2025_10_19_185343_create_cat_product_categories_table.php:19`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 20 |
| `product_id` | `$table->foreignId('product_id')->constrained((new Product)->getTable())->onDelete('cascade');` | 21 |
| `category_id` | `$table->foreignId('category_id')->constrained((new Category)->getTable())->onDelete('cascade');` | 22 |

## `cat_product_collections`

Source: `Modules/Catalog/database/migrations/2025_10_19_185348_create_cat_product_collections_table.php:19`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 20 |
| `product_id` | `$table->foreignId('product_id')->constrained((new Product)->getTable())->onDelete('cascade');` | 21 |
| `collection_id` | `$table->foreignId('collection_id')->constrained((new Collection)->getTable())->onDelete('cascade');` | 22 |

## `cat_product_offers`

Source: `Modules/Catalog/database/migrations/2025_10_19_185426_create_cat_product_offers_table.php:18`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 19 |
| `product_id` | `$table->foreignId('product_id')->constrained((new Product)->getTable())->cascadeOnDelete();` | 20 |
| `price` | `$table->float('price', 12, 2)->default(0);` | 21 |
| `inventory_quantity` | `$table->integer('inventory_quantity')->default(0);` | 22 |
| `is_active` | `$table->boolean('is_active')->default(true)->comment('Активность');` | 23 |
| `timestamps()` | `$table->timestamps();` | 24 |
| `softDeletes()` | `$table->softDeletes();` | 25 |

## `cat_product_offer_property_values`

Source: `Modules/Catalog/database/migrations/2025_10_19_185427_create_cat_product_offer_property_values_table.php:16`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 17 |
| `product_offer_id` | `$table->foreignId('product_offer_id')->constrained((new ProductOffer)->getTable())->cascadeOnDelete();` | 18 |
| `property_value_id` | `$table->foreignId('property_value_id')->constrained((new PropertyValue)->getTable())->cascadeOnDelete();` | 19 |
| `unique()` | `$table->unique(['product_offer_id', 'property_value_id'], 'cpo_pv_unique');` | 20 |

## `cat_specification_groups`

Source: `Modules/Catalog/database/migrations/2025_10_21_171356_create_cat_specification_groups_table.php:18`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 19 |
| `name` | `$table->string('name', 100);` | 20 |
| `created_by_id` | `$table->foreignId('created_by_id')->nullable()->constrained((new User)->getTable())->nullOnDelete();` | 21 |
| `sort_order` | `$table->unsignedMediumInteger('sort_order')->default(500)->comment('Сортировка');` | 22 |
| `timestamps()` | `$table->timestamps();` | 23 |
| `softDeletes()` | `$table->softDeletes();` | 24 |

## `cat_product_specification_groups`

Source: `Modules/Catalog/database/migrations/2025_10_21_172114_create_cat_product_specification_groups_table.php:19`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 20 |
| `product_id` | `$table->foreignId('product_id')->constrained((new Product)->getTable())->cascadeOnDelete();` | 21 |
| `group_id` | `$table->foreignId('group_id')->constrained((new SpecificationGroup)->getTable())->cascadeOnDelete();` | 22 |
| `content` | `$table->json('content')->comment('Контент блока');` | 23 |
| `sort_order` | `$table->unsignedMediumInteger('sort_order')->default(500)->comment('Сортировка');` | 24 |

## `cat_product_specification_options`

Source: `Modules/Catalog/database/migrations/2025_12_05_205034_create_cat_product_specification_options_table.php:19`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 20 |
| `product_id` | `$table->foreignId('product_id')->constrained((new Product)->getTable())->cascadeOnDelete();` | 21 |
| `group_id` | `$table->foreignId('group_id')->constrained((new ProductSpecificationGroup)->getTable())->cascadeOnDelete();` | 22 |
| `content` | `$table->json('content')->comment('Контент блока');` | 23 |
| `sort_order` | `$table->unsignedMediumInteger('sort_order')->default(500)->comment('Сортировка');` | 24 |

## `cat_product_blocks`

Source: `Modules/Catalog/database/migrations/2025_12_05_212217_create_cat_product_blocks_table.php:18`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 19 |
| `product_id` | `$table->foreignId('product_id')->constrained((new Product)->getTable())->cascadeOnDelete();` | 20 |
| `type` | `$table->string('type', 12)->comment('Тип блока');` | 21 |
| `title` | `$table->string('title', 50)->nullable()->comment('Название блока');` | 22 |
| `content` | `$table->json('content')->comment('Контент блока');` | 23 |
| `sort_order` | `$table->unsignedMediumInteger('sort_order')->default(500)->comment('Сортировка');` | 24 |

## `cat_tags`

Source: `Modules/Catalog/database/migrations/2025_12_05_213417_create_cat_tags_table.php:17`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 18 |
| `name` | `$table->string('name', 50)->comment('Название тега');` | 19 |
| `slug` | `$table->string('slug', 60)->unique()->nullable()->comment('Slug тега');` | 20 |
| `timestamps()` | `$table->timestamps();` | 21 |

## `cat_product_tags`

Source: `Modules/Catalog/database/migrations/2025_12_05_213449_create_cat_product_tags_table.php:19`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 20 |
| `product_id` | `$table->foreignId('product_id')->constrained((new Product)->getTable())->cascadeOnDelete();` | 21 |
| `tag_id` | `$table->foreignId('tag_id')->constrained((new Tag)->getTable())->cascadeOnDelete();` | 22 |

## `cat_product_colors`

Source: `Modules/Catalog/database/migrations/2025_12_09_102351_create_cat_product_colors_table.php:15`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 16 |
| `product_id` | `$table->foreignId('product_id')->constrained((new Product)->getTable())->cascadeOnDelete();` | 17 |
| `code` | `$table->string('code', 32)->nullable()->index();` | 18 |

## `cat_collection_blocks`

Source: `Modules/Catalog/database/migrations/2025_12_09_103243_create_cat_collection_blocks_table.php:18`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 19 |
| `collection_id` | `$table->foreignId('collection_id')->nullable()->constrained((new Collection)->getTable())->cascadeOnDelete();` | 20 |
| `type` | `$table->string('type');` | 21 |
| `title` | `$table->string('title')->nullable();` | 22 |
| `content` | `$table->text('content')->nullable();` | 23 |
| `sort_order` | `$table->unsignedMediumInteger('sort_order')->default(500)->comment('Сортировка');` | 24 |
| `timestamps()` | `$table->timestamps();` | 25 |

## `cat_collection_tags`

Source: `Modules/Catalog/database/migrations/2025_12_11_200906_create_cat_collection_tags_table.php:15`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 16 |
| `name` | `$table->string('name');` | 17 |
| `slug` | `$table->string('slug')->unique();` | 18 |
| `description` | `$table->text('description')->nullable();` | 19 |
| `is_active` | `$table->boolean('is_active')->default(true);` | 20 |
| `sort_order` | `$table->integer('sort_order')->default(500);` | 21 |
| `timestamps()` | `$table->timestamps();` | 22 |
| `softDeletes()` | `$table->softDeletes();` | 23 |

## `cat_collection_has_tags`

Source: `Modules/Catalog/database/migrations/2025_12_11_200913_create_cat_collection_has_tags_table.php:17`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 18 |
| `collection_id` | `$table->foreignId('collection_id')->constrained((new Collection)->getTable())->cascadeOnDelete();` | 19 |
| `tag_id` | `$table->foreignId('tag_id')->constrained((new Tag)->getTable())->cascadeOnDelete();` | 20 |

## `sales_sellers`

Source: `Modules/Sales/database/migrations/2025_10_19_172009_create_sales_sellers_table.php:17`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 18 |
| `name` | `$table->string('name');` | 19 |
| `description` | `$table->text('description')->nullable()->comment('Описание');` | 20 |
| `is_trusted` | `$table->boolean('is_trusted')->default(false)->comment('Доверенный продавец');` | 21 |
| `is_active` | `$table->boolean('is_active')->default(true);` | 22 |
| `verified` | `$table->boolean('verified')->nullable();` | 23 |
| `timestamps()` | `$table->timestamps();` | 24 |
| `softDeletes()` | `$table->softDeletes();` | 25 |

## `sales_companies`

Source: `Modules/Sales/database/migrations/2025_10_19_174526_create_sales_companies_table.php:19`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 20 |
| `seller_id` | `$table->foreignId('seller_id')->constrained((new Seller)->getTable())->onDelete('cascade');` | 21 |
| `slug` | `$table->string('slug')->unique()->index();` | 22 |
| `name` | `$table->string('name')->nullable()->comment('Короткое наименование');` | 23 |
| `legal_status` | `$table->string('legal_status', 26)->comment('Юридический статус');` | 24 |
| `legal_name` | `$table->string('legal_name')->nullable()->comment('Юридическое наименование');` | 25 |
| `email` | `$table->string('email')->nullable()->comment('Email');` | 26 |
| `phone_number` | `$table->string('phone_number', 18)->nullable()->comment('Телефон');` | 27 |
| `inn` | `$table->string('inn')->nullable()->comment('ИНН');` | 28 |
| `kpp` | `$table->string('kpp')->nullable()->comment('КПП');` | 29 |
| `ogrn` | `$table->string('ogrn')->nullable()->comment('ОГРН');` | 30 |
| `okpo` | `$table->string('okpo')->nullable()->comment('ОКПО');` | 31 |
| `description` | `$table->text('description')->nullable()->comment('Описание');` | 32 |
| `logo_verified` | `$table->boolean('logo_verified')->default(false)->comment('Логотип подтвержден');` | 33 |
| `verified_by_id` | `$table->foreignId('verified_by_id')->nullable()->constrained((new User)->getTable())->onDelete('cascade');` | 34 |
| `verified_at` | `$table->date('verified_at')->nullable()->index()->comment('Дата подтверждения');` | 35 |
| `timestamps()` | `$table->timestamps();` | 36 |

## `sales_company_business_types`

Source: `Modules/Sales/database/migrations/2025_10_19_180520_create_sales_company_business_types_table.php:17`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 18 |
| `softDeletes()` | `$table->softDeletes();` | 19 |
| `name` | `$table->string('name')->nullable()->comment('Наименование');` | 20 |
| `timestamps()` | `$table->timestamps();` | 21 |

## `sales_seller_business_types`

Source: `Modules/Sales/database/migrations/2025_10_19_180531_create_sales_seller_business_types_table.php:19`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 20 |
| `seller_id` | `$table->foreignId('seller_id')->constrained((new Seller)->getTable())->onDelete('cascade');` | 21 |
| `type_id` | `$table->foreignId('type_id')->constrained((new BusinessType)->getTable())->onDelete('cascade');` | 22 |
| `verified_at` | `$table->date('verified_at')->nullable()->index()->comment('Дата подтверждения');` | 23 |
| `timestamps()` | `$table->timestamps();` | 24 |

## `sales_seller_teams`

Source: `Modules/Sales/database/migrations/2025_10_19_180535_create_sales_seller_teams_table.php:19`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 20 |
| `user_id` | `$table->foreignId('user_id')->constrained((new User)->getTable())->onDelete('cascade');` | 21 |
| `seller_id` | `$table->foreignId('seller_id')->constrained((new Seller)->getTable())->onDelete('cascade');` | 22 |
| `timestamps()` | `$table->timestamps();` | 23 |

## `sales_seller_team_permissions`

Source: `Modules/Sales/database/migrations/2025_10_19_180537_create_sales_seller_team_permissions_table.php:18`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 19 |
| `team_id` | `$table->foreignId('team_id')->constrained((new Team)->getTable())->onDelete('cascade');` | 20 |
| `permission` | `$table->string('permission', 24)->comment('Разрешение');` | 21 |

## `sales_shops`

Source: `Modules/Sales/database/migrations/2025_10_19_181141_create_sales_shops_table.php:18`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 19 |
| `seller_id` | `$table->foreignId('seller_id')->constrained((new Seller)->getTable())->onDelete('cascade');` | 20 |
| `name` | `$table->string('name');` | 21 |
| `tagline` | `$table->string('tagline', 255)->nullable()->comment('Слоган');` | 22 |
| `slug` | `$table->string('slug')->unique()->index();` | 23 |
| `is_active` | `$table->boolean('is_active')->default(true)->comment('Активность');` | 24 |
| `timestamps()` | `$table->timestamps();` | 25 |
| `softDeletes()` | `$table->softDeletes();` | 26 |

## `sales_shop_info`

Source: `Modules/Sales/database/migrations/2025_10_19_183237_create_sales_shop_info_table.php:18`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 19 |
| `shop_id` | `$table->foreignId('shop_id')->constrained((new Shop)->getTable())->onDelete('cascade');` | 20 |
| `name` | `$table->string('name');` | 21 |
| `customer_email` | `$table->string('customer_email')->nullable()->comment('Email покупателя');` | 22 |
| `customer_phone` | `$table->string('customer_phone')->nullable()->comment('Телефон покупателя');` | 23 |
| `verified_at` | `$table->date('verified_at')->nullable()->index()->comment('Дата подтверждения');` | 24 |
| `timestamps()` | `$table->timestamps();` | 25 |

## `sales_seller_warehouses`

Source: `Modules/Sales/database/migrations/2025_10_19_183239_create_sales_seller_warehouses_table.php:19`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 20 |
| `name` | `$table->string('name');` | 21 |
| `seller_id` | `$table->foreignId('seller_id')->constrained((new Seller)->getTable())->onDelete('cascade');` | 22 |
| `city_id` | `$table->foreignId('city_id')->constrained((new Location)->getTable());` | 23 |
| `timestamps()` | `$table->timestamps();` | 24 |

## `sales_seller_settings`

Source: `Modules/Sales/database/migrations/2025_10_20_191620_create_sales_seller_settings_table.php:18`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 19 |
| `seller_id` | `$table->foreignId('seller_id')->constrained((new Seller)->getTable())->onDelete('cascade');` | 20 |
| `number_of_products` | `$table->string('number_of_products', 16);` | 21 |
| `monthly_sales_estimate` | `$table->string('monthly_sales_estimate', 16);` | 22 |
| `average_fulfilment_time` | `$table->string('average_fulfilment_time', 16);` | 23 |
| `other_sales_platforms_used` | `$table->json('other_sales_platforms_used', 16);` | 24 |
| `timestamps()` | `$table->timestamps();` | 25 |

## `sales_seller_contacts`

Source: `Modules/Sales/database/migrations/2025_10_21_071621_create_sales_seller_contacts_table.php:19`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 20 |
| `seller_id` | `$table->foreignId('seller_id')->constrained((new Seller)->getTable())->onDelete('cascade');` | 21 |
| `first_name` | `$table->string('first_name')->nullable()->comment('Имя');` | 22 |
| `middle_name` | `$table->string('middle_name')->nullable()->comment('Отчество');` | 23 |
| `last_name` | `$table->string('last_name')->nullable()->comment('Фамилия');` | 24 |
| `email` | `$table->string('email')->nullable()->comment('Email');` | 25 |
| `phone_number` | `$table->string('phone_number', 18)->nullable()->comment('Телефон');` | 26 |
| `verified_at` | `$table->date('verified_at')->nullable()->index()->comment('Дата подтверждения');` | 27 |
| `verified_by_id` | `$table->foreignId('verified_by_id')->nullable()->constrained((new User)->getTable())->onDelete('cascade');` | 28 |
| `timestamps()` | `$table->timestamps();` | 29 |

## `sales_moderations`

Source: `Modules/Sales/database/migrations/2025_11_27_111513_create_sales_moderations_table.php:19`. Полный migration: [schema-sources.md](schema-sources.md).

| Поле / operation | Declaration | Строка |
| --- | --- | --- |
| `id()` | `$table->id();` | 20 |
| `moderated_by_id` | `$table->foreignId('moderated_by_id')->nullable()->constrained((new User())->getTable());` | 21 |
| `seller_id` | `$table->foreignId('seller_id')->constrained((new Seller())->getTable())->onDelete('cascade');` | 22 |
| `moderatable` | `$table->morphs('moderatable');` | 23 |
| `status` | `$table->string('status', 16);` | 24 |
| `moderation_reason` | `$table->text('moderation_reason')->nullable();` | 25 |
| `moderation_data` | `$table->json('moderation_data')->nullable();` | 26 |
| `moderated_at` | `$table->timestamp('moderated_at')->nullable();` | 27 |
| `timestamps()` | `$table->timestamps();` | 28 |

