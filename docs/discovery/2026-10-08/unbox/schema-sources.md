# Unbox schema — numbered source snapshot

HEAD `4e0d338c5316bb5e216239c4bfb0b62ce60c5a88`. Read-only evidence; filenames and line numbers are original.

## Modules/Buyer/database/migrations/2025_10_21_132001_create_buyer_order_customers_table.php

SHA-256 `4915d7e0dc5b053753244da27dd7894808ae678407cdd5d3407761d4ebf5bfae`; 39 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use App\Models\User;
6: use Buyer\Models\Order\Customer;
7: use Illuminate\Database\Migrations\Migration;
8: use Illuminate\Database\Schema\Blueprint;
9: use Illuminate\Support\Facades\Schema;
10: 
11: return new class extends Migration
12: {
13:     /**
14:      * Run the migrations.
15:      */
16:     public function up(): void
17:     {
18:         Schema::create((new Customer)->getTable(), function (Blueprint $table) {
19:             $table->id();
20:             $table->foreignId('user_id')->constrained((new User)->getTable());
21:             $table->string('first_name');
22:             $table->string('middle_name')->nullable();
23:             $table->string('last_name');
24:             $table->string('email')->nullable();
25:             $table->string('phone')->nullable();
26:             $table->string('comment')->nullable();
27:             $table->timestamps();
28:             $table->softDeletes();
29:         });
30:     }
31: 
32:     /**
33:      * Reverse the migrations.
34:      */
35:     public function down(): void
36:     {
37:         Schema::dropIfExists((new Customer)->getTable());
38:     }
39: };
```

## Modules/Buyer/database/migrations/2025_10_21_132002_create_buyer_orders_table.php

SHA-256 `6cfc36f6acc4160161bc065b29c5fd42e01656ef321aabb60afa86fab5b6380d`; 52 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use App\Models\Geo\Address;
6: use App\Models\User;
7: use Buyer\Models\Order\Customer;
8: use Buyer\Models\Order\Order;
9: use Illuminate\Database\Migrations\Migration;
10: use Illuminate\Database\Schema\Blueprint;
11: use Illuminate\Support\Facades\Schema;
12: use Sales\Models\Shop\Shop;
13: 
14: return new class extends Migration
15: {
16:     /**
17:      * Run the migrations.
18:      */
19:     public function up(): void
20:     {
21:         Schema::create((new Order)->getTable(), function (Blueprint $table) {
22:             $table->id();
23:             $table->foreignId('user_id')->constrained((new User)->getTable());
24:             $table->foreignId('shop_id')->constrained((new Shop)->getTable());
25:             $table->foreignId('customer_id')->constrained((new Customer)->getTable());
26:             $table->foreignId('delivery_address_id')->constrained((new Address)->getTable());
27:             $table->string('order_number', 30)->index();
28:             $table->string('status', 16)->index()->comment('Статус заказа');
29:             $table->string('payment_method', 16)->index()->comment('Метод оплаты');
30:             $table->string('payment_status', 16)->index()->comment('Статус платежа');
31:             $table->float('gift_card_amount', 12, 2)->default(0);
32:             $table->float('total_price', 12, 2)->default(0);
33:             $table->float('total_discount', 12, 2)->default(0);
34:             $table->float('total_tax', 12, 2)->default(0);
35:             $table->float('total_shipping', 12, 2)->default(0);
36:             $table->float('total_shipping_discount', 12, 2)->default(0);
37:             $table->float('total_shipping_tax', 12, 2)->default(0);
38:             $table->string('tracking_number', 30)->nullable()->index()->comment('Номер отслеживания');
39:             $table->dateTime('pickup_date')->nullable();
40:             $table->text('comment')->nullable();
41:             $table->timestamps();
42:         });
43:     }
44: 
45:     /**
46:      * Reverse the migrations.
47:      */
48:     public function down(): void
49:     {
50:         Schema::dropIfExists((new Order)->getTable());
51:     }
52: };
```

## Modules/Buyer/database/migrations/2025_10_21_132419_create_buyer_order_items_table.php

SHA-256 `e36228e97d635fa3ec0315ba86a35ffd38aa10e4814434e03239b85a76a85f37`; 43 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Buyer\Models\Order\Item;
6: use Buyer\Models\Order\Order;
7: use Catalog\Models\Product\Product;
8: use Catalog\Models\Product\ProductOffer;
9: use Illuminate\Database\Migrations\Migration;
10: use Illuminate\Database\Schema\Blueprint;
11: use Illuminate\Support\Facades\Schema;
12: 
13: return new class extends Migration
14: {
15:     /**
16:      * Run the migrations.
17:      */
18:     public function up(): void
19:     {
20:         Schema::create((new Item)->getTable(), function (Blueprint $table) {
21:             $table->id();
22:             $table->foreignId('order_id')->constrained((new Order)->getTable())->cascadeOnDelete();
23:             $table->foreignId('product_id')->constrained((new Product)->getTable());
24:             $table->foreignId('offer_id')->constrained((new ProductOffer)->getTable());
25:             $table->json('offer_properties')->nullable();
26:             $table->integer('quantity')->default(0);
27:             $table->float('unit_price', 12, 2)->default(0);
28:             $table->float('total_price', 12, 2)->default(0);
29:             $table->float('discount_amount', 12, 2)->default(0);
30:             $table->float('tax_amount', 12, 2)->default(0);
31:             $table->text('comment')->nullable();
32:             $table->timestamps();
33:         });
34:     }
35: 
36:     /**
37:      * Reverse the migrations.
38:      */
39:     public function down(): void
40:     {
41:         Schema::dropIfExists((new Item)->getTable());
42:     }
43: };
```

## Modules/Buyer/database/migrations/2025_10_21_132614_create_buyer_order_updates_table.php

SHA-256 `2826201bdf435dbd1c632182db2d60ec14d373171d3bcecfb5d1cee4991e5c34`; 36 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use App\Models\User;
6: use Buyer\Models\Order\Order;
7: use Buyer\Models\Order\OrderUpdate;
8: use Illuminate\Database\Migrations\Migration;
9: use Illuminate\Database\Schema\Blueprint;
10: use Illuminate\Support\Facades\Schema;
11: 
12: return new class extends Migration
13: {
14:     /**
15:      * Run the migrations.
16:      */
17:     public function up(): void
18:     {
19:         Schema::create((new OrderUpdate)->getTable(), function (Blueprint $table) {
20:             $table->id();
21:             $table->foreignId('order_id')->constrained((new Order)->getTable())->cascadeOnDelete();
22:             $table->foreignId('user_id')->constrained((new User)->getTable())->cascadeOnDelete()->nullable();
23:             $table->string('action', 16);
24:             $table->json('data')->nullable();
25:             $table->dateTime('date_added')->default(now());
26:         });
27:     }
28: 
29:     /**
30:      * Reverse the migrations.
31:      */
32:     public function down(): void
33:     {
34:         Schema::dropIfExists((new OrderUpdate)->getTable());
35:     }
36: };
```

## Modules/Buyer/database/migrations/2025_10_21_134028_create_buyer_order_refund_requests_table.php

SHA-256 `475354eda811f52288e04c34152eeb47be322e352296703099e689e7bdc46142`; 40 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use App\Models\User;
6: use Buyer\Models\Order\Order;
7: use Buyer\Models\Order\RefundRequest;
8: use Illuminate\Database\Migrations\Migration;
9: use Illuminate\Database\Schema\Blueprint;
10: use Illuminate\Support\Facades\Schema;
11: 
12: return new class extends Migration
13: {
14:     /**
15:      * Run the migrations.
16:      */
17:     public function up(): void
18:     {
19:         Schema::create((new RefundRequest)->getTable(), function (Blueprint $table) {
20:             $table->id();
21:             $table->foreignId('order_id')->constrained((new Order)->getTable())->cascadeOnDelete();
22:             $table->foreignId('user_id')->constrained((new User)->getTable())->cascadeOnDelete();
23:             $table->string('status', 16)->index()->comment('Статус запроса на возврат');
24:             $table->text('reason')->nullable()->comment('Причина возврата');
25:             $table->float('amount', 12, 2)->default(0);
26:             $table->float('shipping_cost', 12, 2)->default(0);
27:             $table->float('total_cost', 12, 2)->default(0);
28:             $table->float('total_discount', 12, 2)->default(0);
29:             $table->timestamps();
30:         });
31:     }
32: 
33:     /**
34:      * Reverse the migrations.
35:      */
36:     public function down(): void
37:     {
38:         Schema::dropIfExists((new RefundRequest)->getTable());
39:     }
40: };
```

## Modules/Catalog/database/migrations/2025_10_19_180840_create_cat_categories_table.php

SHA-256 `eeb899910f798e2bfd8c58c5b691d40da447de31d41d69ffa2a0f2167b2af7fc`; 41 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use App\Models\User;
6: use Catalog\Models\Category;
7: use Illuminate\Database\Migrations\Migration;
8: use Illuminate\Database\Schema\Blueprint;
9: use Illuminate\Support\Facades\Schema;
10: 
11: return new class extends Migration
12: {
13:     /**
14:      * Run the migrations.
15:      */
16:     public function up(): void
17:     {
18:         Schema::create((new Category)->getTable(), function (Blueprint $table) {
19:             $table->id();
20:             $table->foreignId('suggested_by_id')->nullable()->constrained((new User)->getTable())->onDelete('cascade');
21:             $table->foreignId('parent_id')->nullable()->constrained((new Category)->getTable());
22:             $table->string('name');
23:             $table->string('slug')->unique()->index();
24:             $table->text('description')->nullable();
25:             $table->unsignedMediumInteger('sort_order')->default(500)->comment('Сортировка');
26:             $table->boolean('is_active')->default(true)->comment('Активность');
27:             $table->date('verified_at')->nullable()->index()->comment('Дата подтверждения');
28:             $table->foreignId('verified_by_id')->nullable()->constrained((new User)->getTable())->onDelete('cascade');
29:             $table->timestamps();
30:             $table->softDeletes();
31:         });
32:     }
33: 
34:     /**
35:      * Reverse the migrations.
36:      */
37:     public function down(): void
38:     {
39:         Schema::dropIfExists((new Category)->getTable());
40:     }
41: };
```

## Modules/Catalog/database/migrations/2025_10_19_180850_create_cat_brands_table.php

SHA-256 `9057d2c0915a1862339459cd3e7a7c0dc2bca5e7bd2393d8c92d13ed9f00cd9b`; 32 lines.

```text
1: <?php
2: 
3: use Illuminate\Database\Migrations\Migration;
4: use Illuminate\Database\Schema\Blueprint;
5: use Illuminate\Support\Facades\Schema;
6: 
7: return new class extends Migration
8: {
9:     /**
10:      * Run the migrations.
11:      */
12:     public function up(): void
13:     {
14:         Schema::create('cat_brands', function (Blueprint $table) {
15:             $table->id();
16:             $table->string('name');
17:             $table->string('slug')->unique()->index();
18:             $table->text('description')->nullable();
19:             $table->unsignedMediumInteger('sort_order')->default(500)->comment('Сортировка');
20:             $table->timestamps();
21:             $table->softDeletes();
22:         });
23:     }
24: 
25:     /**
26:      * Reverse the migrations.
27:      */
28:     public function down(): void
29:     {
30:         Schema::dropIfExists('cat_brands');
31:     }
32: };
```

## Modules/Catalog/database/migrations/2025_10_19_185324_create_cat_collections_table.php

SHA-256 `6a00746cd3661cdf3c2aa083ced23181c13dae2af2ecb9527ed0da68f8fb8213`; 41 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Catalog\Models\Collection\Collection;
6: use Illuminate\Database\Migrations\Migration;
7: use Illuminate\Database\Schema\Blueprint;
8: use Illuminate\Support\Facades\Schema;
9: use Sales\Models\Shop\Shop;
10: 
11: return new class extends Migration
12: {
13:     /**
14:      * Run the migrations.
15:      */
16:     public function up(): void
17:     {
18:         Schema::create((new Collection)->getTable(), function (Blueprint $table) {
19:             $table->id();
20:             $table->foreignId('shop_id')->nullable()->constrained((new Shop)->getTable())->onDelete('cascade');
21:             $table->string('name');
22:             $table->string('slug')->unique()->index();
23:             $table->text('description')->nullable();
24:             $table->string('subdescription', 255)->nullable();
25:             $table->unsignedMediumInteger('sort_order')->default(500)->comment('Сортировка');
26:             $table->boolean('is_active')->default(true)->comment('Активность');
27:             $table->boolean('is_main')->default(false)->comment('Главная');
28:             $table->boolean('verified')->nullable()->comment('Подтверждена');
29:             $table->softDeletes();
30:             $table->timestamps();
31:         });
32:     }
33: 
34:     /**
35:      * Reverse the migrations.
36:      */
37:     public function down(): void
38:     {
39:         Schema::dropIfExists((new Collection)->getTable());
40:     }
41: };
```

## Modules/Catalog/database/migrations/2025_10_19_185330_create_cat_properties_table.php

SHA-256 `3e24cfbd1a739722bf8857511c5840fee7c6506deffa28ed9ce7b57055ca8b55`; 32 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Catalog\Enums\Product\PropertyType;
6: use Catalog\Models\Property;
7: use Illuminate\Database\Migrations\Migration;
8: use Illuminate\Database\Schema\Blueprint;
9: use Illuminate\Support\Facades\Schema;
10: use Sales\Models\Shop\Shop;
11: 
12: return new class extends Migration
13: {
14:     public function up(): void
15:     {
16:         Schema::create((new Property)->getTable(), function (Blueprint $table) {
17:             $table->id();
18:             $table->foreignId('shop_id')->nullable()->constrained((new Shop)->getTable())->nullOnDelete();
19:             $table->string('type', 12)->default(PropertyType::Text->value)->comment('Тип свойства: [text, integer, decimal]');
20:             $table->string('control', 16)->nullable()->comment('Контрол создания свойств: например colorpicker для строкового свойства');
21:             $table->string('unit', 12)->nullable()->comment('Единица измерения');
22:             $table->string('name', 50)->comment('Название свойства');
23:             $table->string('code', 60)->index()->nullable()->comment('Код свойства');
24:             $table->unsignedSmallInteger('sort_order')->default(1)->comment('Сортировка');
25:         });
26:     }
27: 
28:     public function down(): void
29:     {
30:         Schema::dropIfExists((new Property)->getTable());
31:     }
32: };
```

## Modules/Catalog/database/migrations/2025_10_19_185331_create_cat_products_table.php

SHA-256 `ce37a5d4730237ed01c707854fb3b5ed3835953ba17ebc8fa23901be52da1c2e`; 60 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use App\Models\User;
6: use Catalog\Enums\Product\Status;
7: use Catalog\Models\Brand;
8: use Catalog\Models\Product\Product;
9: use Illuminate\Database\Migrations\Migration;
10: use Illuminate\Database\Schema\Blueprint;
11: use Illuminate\Support\Facades\Schema;
12: use Sales\Models\Shop\Shop;
13: 
14: return new class extends Migration
15: {
16:     /**
17:      * Run the migrations.
18:      */
19:     public function up(): void
20:     {
21:         Schema::create((new Product)->getTable(), function (Blueprint $table) {
22:             $table->id();
23:             $table->foreignId('shop_id')->constrained((new Shop)->getTable())->onDelete('cascade');
24:             $table->foreignId('created_by_id')->constrained((new User)->getTable());
25:             $table->string('name');
26:             $table->string('subtitle')->nullable();
27:             $table->string('slug')->unique()->index();
28:             $table->foreignId('brand_id')->nullable()->constrained((new Brand)->getTable())->nullOnDelete();
29:             $table->text('description')->nullable();
30:             $table->json('content')->nullable();
31:             $table->unsignedMediumInteger('sort_order')->default(500)->comment('Сортировка');
32:             $table->string('inventory_policy', 16)->comment('Политика учета количества');
33:             $table->float('price', 12, 2)->nullable()->comment('Цена');
34:             $table->float('old_price', 12, 2)->nullable()->comment('Старая цена');
35:             $table->unsignedMediumInteger('base_weight')->nullable()->comment('Базовый вес товара');
36:             $table->unsignedSmallInteger('base_length')->nullable()->comment('Базовая длина (мм)');
37:             $table->unsignedSmallInteger('base_width')->nullable()->comment('Базовая ширина (мм)');
38:             $table->unsignedSmallInteger('base_height')->nullable()->comment('Базовая высота (мм)');
39:             $table->unsignedSmallInteger('measure_ratio')->default(1)->comment('Коэффициент единицы измерения');
40:             $table->string('condition', 10);
41:             $table->boolean('is_new')->default(false)->comment('Новое');
42:             $table->boolean('is_sale')->default(false)->comment('Распродажа');
43:             $table->boolean('is_discount')->default(false)->comment('Скидка');
44:             $table->boolean('is_hit')->default(false)->comment('Хит');
45:             $table->boolean('is_active')->default(true)->comment('Активность');
46:             $table->date('published_at')->nullable()->index()->comment('Дата публикации');
47:             $table->string('status', 16)->index()->default(Status::Draft->value)->comment('Статус');
48:             $table->softDeletes();
49:             $table->timestamps();
50:         });
51:     }
52: 
53:     /**
54:      * Reverse the migrations.
55:      */
56:     public function down(): void
57:     {
58:         Schema::dropIfExists((new Product)->getTable());
59:     }
60: };
```

## Modules/Catalog/database/migrations/2025_10_19_185332_create_cat_product_properties_table.php

SHA-256 `34e45d9601eaa338ad0fb6a55554277749c8601e142d3766a0067f49e4b47181`; 35 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Catalog\Models\Product\Product;
6: use Catalog\Models\Product\ProductProperty;
7: use Catalog\Models\Property;
8: use Illuminate\Database\Migrations\Migration;
9: use Illuminate\Database\Schema\Blueprint;
10: use Illuminate\Support\Facades\Schema;
11: 
12: return new class extends Migration
13: {
14:     /**
15:      * Run the migrations.
16:      */
17:     public function up(): void
18:     {
19:         Schema::create((new ProductProperty)->getTable(), function (Blueprint $table) {
20:             $table->id();
21:             $table->foreignId('product_id')->constrained((new Product)->getTable())->cascadeOnDelete();
22:             $table->foreignId('property_id')->constrained((new Property)->getTable())->cascadeOnDelete();
23:             $table->json('available_codes')->nullable();
24:             $table->unique(['product_id', 'property_id']);
25:         });
26:     }
27: 
28:     /**
29:      * Reverse the migrations.
30:      */
31:     public function down(): void
32:     {
33:         Schema::dropIfExists((new ProductProperty)->getTable());
34:     }
35: };
```

## Modules/Catalog/database/migrations/2025_10_19_185334_create_cat_property_values_table.php

SHA-256 `179362e321b284b47255262b88ca2c15428f1d5b591671ef4a426853b3d84d9c`; 36 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Catalog\Models\Property;
6: use Catalog\Models\PropertyValue;
7: use Illuminate\Database\Migrations\Migration;
8: use Illuminate\Database\Schema\Blueprint;
9: use Illuminate\Support\Facades\Schema;
10: 
11: return new class extends Migration
12: {
13:     /**
14:      * Run the migrations.
15:      */
16:     public function up(): void
17:     {
18:         Schema::create((new PropertyValue)->getTable(), function (Blueprint $table) {
19:             $table->id();
20:             $table->foreignId('property_id')->constrained((new Property)->getTable())->cascadeOnDelete();
21:             $table->string('label', 32)->nullable();
22:             $table->string('code', 32)->nullable()->index();
23:             $table->string('value_text')->nullable()->index();
24:             $table->bigInteger('value_integer')->index()->nullable();
25:             $table->decimal('value_decimal', 12, 4)->index()->nullable();
26:         });
27:     }
28: 
29:     /**
30:      * Reverse the migrations.
31:      */
32:     public function down(): void
33:     {
34:         Schema::dropIfExists((new PropertyValue)->getTable());
35:     }
36: };
```

## Modules/Catalog/database/migrations/2025_10_19_185343_create_cat_product_categories_table.php

SHA-256 `083390df363439d5a12c3bee87b9cfbf0907f7df20b2425ee4c7b3f330ae4bca`; 33 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Catalog\Models\Category;
6: use Catalog\Models\Product\Product;
7: use Catalog\Models\Product\ProductCategory;
8: use Illuminate\Database\Migrations\Migration;
9: use Illuminate\Database\Schema\Blueprint;
10: use Illuminate\Support\Facades\Schema;
11: 
12: return new class extends Migration
13: {
14:     /**
15:      * Run the migrations.
16:      */
17:     public function up(): void
18:     {
19:         Schema::create((new ProductCategory)->getTable(), function (Blueprint $table) {
20:             $table->id();
21:             $table->foreignId('product_id')->constrained((new Product)->getTable())->onDelete('cascade');
22:             $table->foreignId('category_id')->constrained((new Category)->getTable())->onDelete('cascade');
23:         });
24:     }
25: 
26:     /**
27:      * Reverse the migrations.
28:      */
29:     public function down(): void
30:     {
31:         Schema::dropIfExists((new ProductCategory)->getTable());
32:     }
33: };
```

## Modules/Catalog/database/migrations/2025_10_19_185348_create_cat_product_collections_table.php

SHA-256 `e055f88d203fe9643924bb3b2bc8c6e04d7ba611930e9a9881eeec38aab36548`; 33 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Catalog\Models\Product\Product;
6: use Catalog\Models\Product\ProductCollection;
7: use Illuminate\Database\Migrations\Migration;
8: use Illuminate\Database\Schema\Blueprint;
9: use Illuminate\Support\Facades\Schema;
10: use Catalog\Models\Collection\Collection;
11: 
12: return new class extends Migration
13: {
14:     /**
15:      * Run the migrations.
16:      */
17:     public function up(): void
18:     {
19:         Schema::create((new ProductCollection)->getTable(), function (Blueprint $table) {
20:             $table->id();
21:             $table->foreignId('product_id')->constrained((new Product)->getTable())->onDelete('cascade');
22:             $table->foreignId('collection_id')->constrained((new Collection)->getTable())->onDelete('cascade');
23:         });
24:     }
25: 
26:     /**
27:      * Reverse the migrations.
28:      */
29:     public function down(): void
30:     {
31:         Schema::dropIfExists((new ProductCollection)->getTable());
32:     }
33: };
```

## Modules/Catalog/database/migrations/2025_10_19_185426_create_cat_product_offers_table.php

SHA-256 `d8fb7664bd465e95a0bcccafb9d5fd93936ffc0d8cbfe1cdc97a5db014f5b59e`; 37 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Catalog\Models\Product\Product;
6: use Catalog\Models\Product\ProductOffer;
7: use Illuminate\Database\Migrations\Migration;
8: use Illuminate\Database\Schema\Blueprint;
9: use Illuminate\Support\Facades\Schema;
10: 
11: return new class extends Migration
12: {
13:     /**
14:      * Run the migrations.
15:      */
16:     public function up(): void
17:     {
18:         Schema::create((new ProductOffer)->getTable(), function (Blueprint $table) {
19:             $table->id();
20:             $table->foreignId('product_id')->constrained((new Product)->getTable())->cascadeOnDelete();
21:             $table->float('price', 12, 2)->default(0);
22:             $table->integer('inventory_quantity')->default(0);
23:             $table->boolean('is_active')->default(true)->comment('Активность');
24:             $table->timestamps();
25:             $table->softDeletes();
26:         });
27: 
28:     }
29: 
30:     /**
31:      * Reverse the migrations.
32:      */
33:     public function down(): void
34:     {
35:         Schema::dropIfExists((new ProductOffer)->getTable());
36:     }
37: };
```

## Modules/Catalog/database/migrations/2025_10_19_185427_create_cat_product_offer_property_values_table.php

SHA-256 `4529c2e23a461de30875b7241366bbf40e7f49355e9712936b0a675749838058`; 28 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Catalog\Models\Product\ProductOffer;
6: use Catalog\Models\Product\ProductOfferPropertyValue;
7: use Catalog\Models\PropertyValue;
8: use Illuminate\Database\Migrations\Migration;
9: use Illuminate\Database\Schema\Blueprint;
10: use Illuminate\Support\Facades\Schema;
11: 
12: return new class extends Migration
13: {
14:     public function up(): void
15:     {
16:         Schema::create((new ProductOfferPropertyValue)->getTable(), function (Blueprint $table) {
17:             $table->id();
18:             $table->foreignId('product_offer_id')->constrained((new ProductOffer)->getTable())->cascadeOnDelete();
19:             $table->foreignId('property_value_id')->constrained((new PropertyValue)->getTable())->cascadeOnDelete();
20:             $table->unique(['product_offer_id', 'property_value_id'], 'cpo_pv_unique');
21:         });
22:     }
23: 
24:     public function down(): void
25:     {
26:         Schema::dropIfExists((new ProductOfferPropertyValue)->getTable());
27:     }
28: };
```

## Modules/Catalog/database/migrations/2025_10_21_171356_create_cat_specification_groups_table.php

SHA-256 `50654b38e0b1c6793726088bd7c97194e5ef8f30d45d926b06bf4efd7061997b`; 35 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use App\Models\User;
6: use Catalog\Models\Product\SpecificationGroup;
7: use Illuminate\Database\Migrations\Migration;
8: use Illuminate\Database\Schema\Blueprint;
9: use Illuminate\Support\Facades\Schema;
10: 
11: return new class extends Migration
12: {
13:     /**
14:      * Run the migrations.
15:      */
16:     public function up(): void
17:     {
18:         Schema::create((new SpecificationGroup)->getTable(), function (Blueprint $table) {
19:             $table->id();
20:             $table->string('name', 100);
21:             $table->foreignId('created_by_id')->nullable()->constrained((new User)->getTable())->nullOnDelete();
22:             $table->unsignedMediumInteger('sort_order')->default(500)->comment('Сортировка');
23:             $table->timestamps();
24:             $table->softDeletes();
25:         });
26:     }
27: 
28:     /**
29:      * Reverse the migrations.
30:      */
31:     public function down(): void
32:     {
33:         Schema::dropIfExists((new SpecificationGroup)->getTable());
34:     }
35: };
```

## Modules/Catalog/database/migrations/2025_10_21_172114_create_cat_product_specification_groups_table.php

SHA-256 `490951bfff33cacecff812b33c7539cb00630a5e7f8b0d9d95078cb83f185598`; 35 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Catalog\Models\Product\Product;
6: use Catalog\Models\Product\ProductSpecificationGroup;
7: use Catalog\Models\Product\SpecificationGroup;
8: use Illuminate\Database\Migrations\Migration;
9: use Illuminate\Database\Schema\Blueprint;
10: use Illuminate\Support\Facades\Schema;
11: 
12: return new class extends Migration
13: {
14:     /**
15:      * Run the migrations.
16:      */
17:     public function up(): void
18:     {
19:         Schema::create((new ProductSpecificationGroup)->getTable(), function (Blueprint $table) {
20:             $table->id();
21:             $table->foreignId('product_id')->constrained((new Product)->getTable())->cascadeOnDelete();
22:             $table->foreignId('group_id')->constrained((new SpecificationGroup)->getTable())->cascadeOnDelete();
23:             $table->json('content')->comment('Контент блока');
24:             $table->unsignedMediumInteger('sort_order')->default(500)->comment('Сортировка');
25:         });
26:     }
27: 
28:     /**
29:      * Reverse the migrations.
30:      */
31:     public function down(): void
32:     {
33:         Schema::dropIfExists((new ProductSpecificationGroup)->getTable());
34:     }
35: };
```

## Modules/Catalog/database/migrations/2025_12_05_205034_create_cat_product_specification_options_table.php

SHA-256 `9d4afa6cf9a2803ddbdcc18a5eac7e5f8d6b8d500868795276ad2aa22a62dd4c`; 35 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Catalog\Models\Product\Product;
6: use Catalog\Models\Product\ProductSpecificationGroup;
7: use Catalog\Models\Product\ProductSpecificationOption;
8: use Illuminate\Database\Migrations\Migration;
9: use Illuminate\Database\Schema\Blueprint;
10: use Illuminate\Support\Facades\Schema;
11: 
12: return new class extends Migration
13: {
14:     /**
15:      * Run the migrations.
16:      */
17:     public function up(): void
18:     {
19:         Schema::create((new ProductSpecificationOption)->getTable(), function (Blueprint $table) {
20:             $table->id();
21:             $table->foreignId('product_id')->constrained((new Product)->getTable())->cascadeOnDelete();
22:             $table->foreignId('group_id')->constrained((new ProductSpecificationGroup)->getTable())->cascadeOnDelete();
23:             $table->json('content')->comment('Контент блока');
24:             $table->unsignedMediumInteger('sort_order')->default(500)->comment('Сортировка');
25:         });
26:     }
27: 
28:     /**
29:      * Reverse the migrations.
30:      */
31:     public function down(): void
32:     {
33:         Schema::dropIfExists((new ProductSpecificationOption)->getTable());
34:     }
35: };
```

## Modules/Catalog/database/migrations/2025_12_05_212217_create_cat_product_blocks_table.php

SHA-256 `abbac3a3829119d224406f96ca254426a1ea40ab381f1050fa9ef9686ad31b92`; 35 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Catalog\Models\Product\Product;
6: use Catalog\Models\Product\ProductBlock;
7: use Illuminate\Database\Migrations\Migration;
8: use Illuminate\Database\Schema\Blueprint;
9: use Illuminate\Support\Facades\Schema;
10: 
11: return new class extends Migration
12: {
13:     /**
14:      * Run the migrations.
15:      */
16:     public function up(): void
17:     {
18:         Schema::create((new ProductBlock)->getTable(), function (Blueprint $table) {
19:             $table->id();
20:             $table->foreignId('product_id')->constrained((new Product)->getTable())->cascadeOnDelete();
21:             $table->string('type', 12)->comment('Тип блока');
22:             $table->string('title', 50)->nullable()->comment('Название блока');
23:             $table->json('content')->comment('Контент блока');
24:             $table->unsignedMediumInteger('sort_order')->default(500)->comment('Сортировка');
25:         });
26:     }
27: 
28:     /**
29:      * Reverse the migrations.
30:      */
31:     public function down(): void
32:     {
33:         Schema::dropIfExists((new ProductBlock)->getTable());
34:     }
35: };
```

## Modules/Catalog/database/migrations/2025_12_05_213417_create_cat_tags_table.php

SHA-256 `e7956309a3b4045ebb5df0a5b316f521005e23a9ff2d931911fcfa56de823ef7`; 32 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Catalog\Models\Tag;
6: use Illuminate\Database\Migrations\Migration;
7: use Illuminate\Database\Schema\Blueprint;
8: use Illuminate\Support\Facades\Schema;
9: 
10: return new class extends Migration
11: {
12:     /**
13:      * Run the migrations.
14:      */
15:     public function up(): void
16:     {
17:         Schema::create((new Tag)->getTable(), function (Blueprint $table) {
18:             $table->id();
19:             $table->string('name', 50)->comment('Название тега');
20:             $table->string('slug', 60)->unique()->nullable()->comment('Slug тега');
21:             $table->timestamps();
22:         });
23:     }
24: 
25:     /**
26:      * Reverse the migrations.
27:      */
28:     public function down(): void
29:     {
30:         Schema::dropIfExists((new Tag)->getTable());
31:     }
32: };
```

## Modules/Catalog/database/migrations/2025_12_05_213449_create_cat_product_tags_table.php

SHA-256 `da50f3a04c55b60602ff11c61aeb4ef6489597baa349292c03d1dbf1008ee90c`; 33 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Catalog\Models\Product\Product;
6: use Catalog\Models\Product\ProductTag;
7: use Catalog\Models\Tag;
8: use Illuminate\Database\Migrations\Migration;
9: use Illuminate\Database\Schema\Blueprint;
10: use Illuminate\Support\Facades\Schema;
11: 
12: return new class extends Migration
13: {
14:     /**
15:      * Run the migrations.
16:      */
17:     public function up(): void
18:     {
19:         Schema::create((new ProductTag)->getTable(), function (Blueprint $table) {
20:             $table->id();
21:             $table->foreignId('product_id')->constrained((new Product)->getTable())->cascadeOnDelete();
22:             $table->foreignId('tag_id')->constrained((new Tag)->getTable())->cascadeOnDelete();
23:         });
24:     }
25: 
26:     /**
27:      * Reverse the migrations.
28:      */
29:     public function down(): void
30:     {
31:         Schema::dropIfExists((new ProductTag)->getTable());
32:     }
33: };
```

## Modules/Catalog/database/migrations/2025_12_09_102351_create_cat_product_colors_table.php

SHA-256 `6c1730745ee4d31be26234c95c81f29a97b361657380dc5e9636ff7f3373ae97`; 29 lines.

```text
1: <?php
2: 
3: use Catalog\Models\Product\Product;
4: use Illuminate\Database\Migrations\Migration;
5: use Illuminate\Database\Schema\Blueprint;
6: use Illuminate\Support\Facades\Schema;
7: 
8: return new class extends Migration
9: {
10:     /**
11:      * Run the migrations.
12:      */
13:     public function up(): void
14:     {
15:         Schema::create('cat_product_colors', function (Blueprint $table) {
16:             $table->id();
17:             $table->foreignId('product_id')->constrained((new Product)->getTable())->cascadeOnDelete();
18:             $table->string('code', 32)->nullable()->index();
19:         });
20:     }
21: 
22:     /**
23:      * Reverse the migrations.
24:      */
25:     public function down(): void
26:     {
27:         Schema::dropIfExists('cat_product_colors');
28:     }
29: };
```

## Modules/Catalog/database/migrations/2025_12_09_103243_create_cat_collection_blocks_table.php

SHA-256 `7590f84bcb2e60fdb1e787decebfdc22e164911d60ddc110e4fb9bf11dd52793`; 36 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Illuminate\Database\Migrations\Migration;
6: use Illuminate\Database\Schema\Blueprint;
7: use Illuminate\Support\Facades\Schema;
8: use Catalog\Models\Collection\Collection;
9: use Catalog\Models\Collection\CollectionBlock;
10: 
11: return new class extends Migration
12: {
13:     /**
14:      * Run the migrations.
15:      */
16:     public function up(): void
17:     {
18:         Schema::create((new CollectionBlock)->getTable(), function (Blueprint $table) {
19:             $table->id();
20:             $table->foreignId('collection_id')->nullable()->constrained((new Collection)->getTable())->cascadeOnDelete();
21:             $table->string('type');
22:             $table->string('title')->nullable();
23:             $table->text('content')->nullable();
24:             $table->unsignedMediumInteger('sort_order')->default(500)->comment('Сортировка');
25:             $table->timestamps();
26:         });
27:     }
28: 
29:     /**
30:      * Reverse the migrations.
31:      */
32:     public function down(): void
33:     {
34:         Schema::dropIfExists((new CollectionBlock)->getTable());
35:     }
36: };
```

## Modules/Catalog/database/migrations/2025_12_11_200906_create_cat_collection_tags_table.php

SHA-256 `ea8d79a71ec016c22430afc740054ada0fba93652346424a45dd5d806d69971d`; 34 lines.

```text
1: <?php
2: 
3: use Catalog\Models\Collection\Tag;
4: use Illuminate\Database\Migrations\Migration;
5: use Illuminate\Database\Schema\Blueprint;
6: use Illuminate\Support\Facades\Schema;
7: 
8: return new class extends Migration
9: {
10:     /**
11:      * Run the migrations.
12:      */
13:     public function up(): void
14:     {
15:         Schema::create((new Tag)->getTable(), function (Blueprint $table) {
16:             $table->id();
17:             $table->string('name');
18:             $table->string('slug')->unique();
19:             $table->text('description')->nullable();
20:             $table->boolean('is_active')->default(true);
21:             $table->integer('sort_order')->default(500);
22:             $table->timestamps();
23:             $table->softDeletes();
24:         });
25:     }
26: 
27:     /**
28:      * Reverse the migrations.
29:      */
30:     public function down(): void
31:     {
32:         Schema::dropIfExists((new Tag)->getTable());
33:     }
34: };
```

## Modules/Catalog/database/migrations/2025_12_11_200913_create_cat_collection_has_tags_table.php

SHA-256 `a586f7a4fa7665b4fd61b1e68cc1333ad3ef52818df35177e432f1247f37ad5b`; 31 lines.

```text
1: <?php
2: 
3: use Catalog\Models\Collection\CollectionHasTag;
4: use Catalog\Models\Collection\Tag;
5: use Illuminate\Database\Migrations\Migration;
6: use Illuminate\Database\Schema\Blueprint;
7: use Illuminate\Support\Facades\Schema;
8: use Catalog\Models\Collection\Collection;
9: 
10: return new class extends Migration
11: {
12:     /**
13:      * Run the migrations.
14:      */
15:     public function up(): void
16:     {
17:         Schema::create((new CollectionHasTag)->getTable(), function (Blueprint $table) {
18:             $table->id();
19:             $table->foreignId('collection_id')->constrained((new Collection)->getTable())->cascadeOnDelete();
20:             $table->foreignId('tag_id')->constrained((new Tag)->getTable())->cascadeOnDelete();
21:         });
22:     }
23: 
24:     /**
25:      * Reverse the migrations.
26:      */
27:     public function down(): void
28:     {
29:         Schema::dropIfExists((new CollectionHasTag)->getTable());
30:     }
31: };
```

## Modules/Sales/database/migrations/2025_10_19_172009_create_sales_sellers_table.php

SHA-256 `7c23dc4e201bfb537d2c3075458d7530eea4ac3930c0dfb50d08a7b2195a3c74`; 36 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Illuminate\Database\Migrations\Migration;
6: use Illuminate\Database\Schema\Blueprint;
7: use Illuminate\Support\Facades\Schema;
8: use Sales\Models\Seller\Seller;
9: 
10: return new class extends Migration
11: {
12:     /**
13:      * Run the migrations.
14:      */
15:     public function up(): void
16:     {
17:         Schema::create((new Seller)->getTable(), function (Blueprint $table) {
18:             $table->id();
19:             $table->string('name');
20:             $table->text('description')->nullable()->comment('Описание');
21:             $table->boolean('is_trusted')->default(false)->comment('Доверенный продавец');
22:             $table->boolean('is_active')->default(true);
23:             $table->boolean('verified')->nullable();
24:             $table->timestamps();
25:             $table->softDeletes();
26:         });
27:     }
28: 
29:     /**
30:      * Reverse the migrations.
31:      */
32:     public function down(): void
33:     {
34:         Schema::dropIfExists((new Seller)->getTable());
35:     }
36: };
```

## Modules/Sales/database/migrations/2025_10_19_174526_create_sales_companies_table.php

SHA-256 `74ed42ca6155238390c2a81ead726467e6cdc6bc96d0fc5d252d976936b96140`; 47 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use App\Models\User;
6: use Illuminate\Database\Migrations\Migration;
7: use Illuminate\Database\Schema\Blueprint;
8: use Illuminate\Support\Facades\Schema;
9: use Sales\Models\Company\Company;
10: use Sales\Models\Seller\Seller;
11: 
12: return new class extends Migration
13: {
14:     /**
15:      * Run the migrations.
16:      */
17:     public function up(): void
18:     {
19:         Schema::create((new Company)->getTable(), function (Blueprint $table) {
20:             $table->id();
21:             $table->foreignId('seller_id')->constrained((new Seller)->getTable())->onDelete('cascade');
22:             $table->string('slug')->unique()->index();
23:             $table->string('name')->nullable()->comment('Короткое наименование');
24:             $table->string('legal_status', 26)->comment('Юридический статус');
25:             $table->string('legal_name')->nullable()->comment('Юридическое наименование');
26:             $table->string('email')->nullable()->comment('Email');
27:             $table->string('phone_number', 18)->nullable()->comment('Телефон');
28:             $table->string('inn')->nullable()->comment('ИНН');
29:             $table->string('kpp')->nullable()->comment('КПП');
30:             $table->string('ogrn')->nullable()->comment('ОГРН');
31:             $table->string('okpo')->nullable()->comment('ОКПО');
32:             $table->text('description')->nullable()->comment('Описание');
33:             $table->boolean('logo_verified')->default(false)->comment('Логотип подтвержден');
34:             $table->foreignId('verified_by_id')->nullable()->constrained((new User)->getTable())->onDelete('cascade');
35:             $table->date('verified_at')->nullable()->index()->comment('Дата подтверждения');
36:             $table->timestamps();
37:         });
38:     }
39: 
40:     /**
41:      * Reverse the migrations.
42:      */
43:     public function down(): void
44:     {
45:         Schema::dropIfExists((new Company)->getTable());
46:     }
47: };
```

## Modules/Sales/database/migrations/2025_10_19_180520_create_sales_company_business_types_table.php

SHA-256 `0c770857e500d0b894d250db8cfcac867d1aac3b2fcaed1cce86a5ac98a394df`; 32 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Illuminate\Database\Migrations\Migration;
6: use Illuminate\Database\Schema\Blueprint;
7: use Illuminate\Support\Facades\Schema;
8: use Sales\Models\Company\BusinessType;
9: 
10: return new class extends Migration
11: {
12:     /**
13:      * Run the migrations.
14:      */
15:     public function up(): void
16:     {
17:         Schema::create((new BusinessType)->getTable(), function (Blueprint $table) {
18:             $table->id();
19:             $table->softDeletes();
20:             $table->string('name')->nullable()->comment('Наименование');
21:             $table->timestamps();
22:         });
23:     }
24: 
25:     /**
26:      * Reverse the migrations.
27:      */
28:     public function down(): void
29:     {
30:         Schema::dropIfExists((new BusinessType)->getTable());
31:     }
32: };
```

## Modules/Sales/database/migrations/2025_10_19_180531_create_sales_seller_business_types_table.php

SHA-256 `3d358cc9e9e29611eb1aaf9394380e95a883801d76c10d2789da4fcf6a9c6f85`; 35 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Illuminate\Database\Migrations\Migration;
6: use Illuminate\Database\Schema\Blueprint;
7: use Illuminate\Support\Facades\Schema;
8: use Sales\Models\Company\BusinessType;
9: use Sales\Models\Seller\Seller;
10: use Sales\Models\Seller\SellerBusinessType;
11: 
12: return new class extends Migration
13: {
14:     /**
15:      * Run the migrations.
16:      */
17:     public function up(): void
18:     {
19:         Schema::create((new SellerBusinessType)->getTable(), function (Blueprint $table) {
20:             $table->id();
21:             $table->foreignId('seller_id')->constrained((new Seller)->getTable())->onDelete('cascade');
22:             $table->foreignId('type_id')->constrained((new BusinessType)->getTable())->onDelete('cascade');
23:             $table->date('verified_at')->nullable()->index()->comment('Дата подтверждения');
24:             $table->timestamps();
25:         });
26:     }
27: 
28:     /**
29:      * Reverse the migrations.
30:      */
31:     public function down(): void
32:     {
33:         Schema::dropIfExists((new SellerBusinessType)->getTable());
34:     }
35: };
```

## Modules/Sales/database/migrations/2025_10_19_180535_create_sales_seller_teams_table.php

SHA-256 `61a92090b0e112f9b153bd616cd4f249b53f0ec8ae95af190a04c5febb9a850c`; 34 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use App\Models\User;
6: use Illuminate\Database\Migrations\Migration;
7: use Illuminate\Database\Schema\Blueprint;
8: use Illuminate\Support\Facades\Schema;
9: use Sales\Models\Seller\Seller;
10: use Sales\Models\Seller\Team;
11: 
12: return new class extends Migration
13: {
14:     /**
15:      * Run the migrations.
16:      */
17:     public function up(): void
18:     {
19:         Schema::create((new Team)->getTable(), function (Blueprint $table) {
20:             $table->id();
21:             $table->foreignId('user_id')->constrained((new User)->getTable())->onDelete('cascade');
22:             $table->foreignId('seller_id')->constrained((new Seller)->getTable())->onDelete('cascade');
23:             $table->timestamps();
24:         });
25:     }
26: 
27:     /**
28:      * Reverse the migrations.
29:      */
30:     public function down(): void
31:     {
32:         Schema::dropIfExists((new Team)->getTable());
33:     }
34: };
```

## Modules/Sales/database/migrations/2025_10_19_180537_create_sales_seller_team_permissions_table.php

SHA-256 `f7cbee56c31f0c402556790bcb19c3181351b647327e46dd727a894788619541`; 32 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Illuminate\Database\Migrations\Migration;
6: use Illuminate\Database\Schema\Blueprint;
7: use Illuminate\Support\Facades\Schema;
8: use Sales\Models\Seller\Team;
9: use Sales\Models\Seller\TeamPermission;
10: 
11: return new class extends Migration
12: {
13:     /**
14:      * Run the migrations.
15:      */
16:     public function up(): void
17:     {
18:         Schema::create((new TeamPermission)->getTable(), function (Blueprint $table) {
19:             $table->id();
20:             $table->foreignId('team_id')->constrained((new Team)->getTable())->onDelete('cascade');
21:             $table->string('permission', 24)->comment('Разрешение');
22:         });
23:     }
24: 
25:     /**
26:      * Reverse the migrations.
27:      */
28:     public function down(): void
29:     {
30:         Schema::dropIfExists((new TeamPermission)->getTable());
31:     }
32: };
```

## Modules/Sales/database/migrations/2025_10_19_181141_create_sales_shops_table.php

SHA-256 `302c6a261ed55f95cae5502edfdd52e9709d32af37d5fcc6eba69300ac4ff46e`; 37 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Illuminate\Database\Migrations\Migration;
6: use Illuminate\Database\Schema\Blueprint;
7: use Illuminate\Support\Facades\Schema;
8: use Sales\Models\Seller\Seller;
9: use Sales\Models\Shop\Shop;
10: 
11: return new class extends Migration
12: {
13:     /**
14:      * Run the migrations.
15:      */
16:     public function up(): void
17:     {
18:         Schema::create((new Shop)->getTable(), function (Blueprint $table) {
19:             $table->id();
20:             $table->foreignId('seller_id')->constrained((new Seller)->getTable())->onDelete('cascade');
21:             $table->string('name');
22:             $table->string('tagline', 255)->nullable()->comment('Слоган');
23:             $table->string('slug')->unique()->index();
24:             $table->boolean('is_active')->default(true)->comment('Активность');
25:             $table->timestamps();
26:             $table->softDeletes();
27:         });
28:     }
29: 
30:     /**
31:      * Reverse the migrations.
32:      */
33:     public function down(): void
34:     {
35:         Schema::dropIfExists((new Shop)->getTable());
36:     }
37: };
```

## Modules/Sales/database/migrations/2025_10_19_183237_create_sales_shop_info_table.php

SHA-256 `4fed5273db7f7961935925d293bedd4b84eb242e1f444f26cb291ec96b67dd27`; 36 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Illuminate\Database\Migrations\Migration;
6: use Illuminate\Database\Schema\Blueprint;
7: use Illuminate\Support\Facades\Schema;
8: use Sales\Models\Shop\Info;
9: use Sales\Models\Shop\Shop;
10: 
11: return new class extends Migration
12: {
13:     /**
14:      * Run the migrations.
15:      */
16:     public function up(): void
17:     {
18:         Schema::create((new Info)->getTable(), function (Blueprint $table) {
19:             $table->id();
20:             $table->foreignId('shop_id')->constrained((new Shop)->getTable())->onDelete('cascade');
21:             $table->string('name');
22:             $table->string('customer_email')->nullable()->comment('Email покупателя');
23:             $table->string('customer_phone')->nullable()->comment('Телефон покупателя');
24:             $table->date('verified_at')->nullable()->index()->comment('Дата подтверждения');
25:             $table->timestamps();
26:         });
27:     }
28: 
29:     /**
30:      * Reverse the migrations.
31:      */
32:     public function down(): void
33:     {
34:         Schema::dropIfExists((new Info)->getTable());
35:     }
36: };
```

## Modules/Sales/database/migrations/2025_10_19_183239_create_sales_seller_warehouses_table.php

SHA-256 `bc38d9d932f12d000b7947ab6e388bb85807503fdc2f4d0a5ff9de643c24a88b`; 35 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use App\Models\Geo\Location;
6: use Illuminate\Database\Migrations\Migration;
7: use Illuminate\Database\Schema\Blueprint;
8: use Illuminate\Support\Facades\Schema;
9: use Sales\Models\Seller\Seller;
10: use Sales\Models\Seller\Warehouse;
11: 
12: return new class extends Migration
13: {
14:     /**
15:      * Run the migrations.
16:      */
17:     public function up(): void
18:     {
19:         Schema::create((new Warehouse)->getTable(), function (Blueprint $table) {
20:             $table->id();
21:             $table->string('name');
22:             $table->foreignId('seller_id')->constrained((new Seller)->getTable())->onDelete('cascade');
23:             $table->foreignId('city_id')->constrained((new Location)->getTable());
24:             $table->timestamps();
25:         });
26:     }
27: 
28:     /**
29:      * Reverse the migrations.
30:      */
31:     public function down(): void
32:     {
33:         Schema::dropIfExists((new Warehouse)->getTable());
34:     }
35: };
```

## Modules/Sales/database/migrations/2025_10_20_191620_create_sales_seller_settings_table.php

SHA-256 `1422c5ef5a83a2a19ddcb7e2776a233cb658209c9c45a9999be96cf5c5522827`; 36 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Illuminate\Database\Migrations\Migration;
6: use Illuminate\Database\Schema\Blueprint;
7: use Illuminate\Support\Facades\Schema;
8: use Sales\Models\Seller\Seller;
9: use Sales\Models\Seller\Settings;
10: 
11: return new class extends Migration
12: {
13:     /**
14:      * Run the migrations.
15:      */
16:     public function up(): void
17:     {
18:         Schema::create((new Settings)->getTable(), function (Blueprint $table) {
19:             $table->id();
20:             $table->foreignId('seller_id')->constrained((new Seller)->getTable())->onDelete('cascade');
21:             $table->string('number_of_products', 16);
22:             $table->string('monthly_sales_estimate', 16);
23:             $table->string('average_fulfilment_time', 16);
24:             $table->json('other_sales_platforms_used', 16);
25:             $table->timestamps();
26:         });
27:     }
28: 
29:     /**
30:      * Reverse the migrations.
31:      */
32:     public function down(): void
33:     {
34:         Schema::dropIfExists((new Settings)->getTable());
35:     }
36: };
```

## Modules/Sales/database/migrations/2025_10_21_071621_create_sales_seller_contacts_table.php

SHA-256 `e3b7560d7f92c6901dcc5985c7ea8c53d17e6798f686f87df7c45493f42e21a6`; 40 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use App\Models\User;
6: use Illuminate\Database\Migrations\Migration;
7: use Illuminate\Database\Schema\Blueprint;
8: use Illuminate\Support\Facades\Schema;
9: use Sales\Models\Seller\Contact;
10: use Sales\Models\Seller\Seller;
11: 
12: return new class extends Migration
13: {
14:     /**
15:      * Run the migrations.
16:      */
17:     public function up(): void
18:     {
19:         Schema::create((new Contact)->getTable(), function (Blueprint $table) {
20:             $table->id();
21:             $table->foreignId('seller_id')->constrained((new Seller)->getTable())->onDelete('cascade');
22:             $table->string('first_name')->nullable()->comment('Имя');
23:             $table->string('middle_name')->nullable()->comment('Отчество');
24:             $table->string('last_name')->nullable()->comment('Фамилия');
25:             $table->string('email')->nullable()->comment('Email');
26:             $table->string('phone_number', 18)->nullable()->comment('Телефон');
27:             $table->date('verified_at')->nullable()->index()->comment('Дата подтверждения');
28:             $table->foreignId('verified_by_id')->nullable()->constrained((new User)->getTable())->onDelete('cascade');
29:             $table->timestamps();
30:         });
31:     }
32: 
33:     /**
34:      * Reverse the migrations.
35:      */
36:     public function down(): void
37:     {
38:         Schema::dropIfExists((new Contact)->getTable());
39:     }
40: };
```

## Modules/Sales/database/migrations/2025_11_27_111513_create_sales_moderations_table.php

SHA-256 `ea8e4a7aead8510a1306f787cdc0da63287cbc3b0f8254befe2f988d152fb444`; 39 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use App\Models\User;
6: use Illuminate\Database\Migrations\Migration;
7: use Illuminate\Database\Schema\Blueprint;
8: use Illuminate\Support\Facades\Schema;
9: use Sales\Models\Seller\SalesModeration;
10: use Sales\Models\Seller\Seller;
11: 
12: return new class extends Migration
13: {
14:     /**
15:      * Run the migrations.
16:      */
17:     public function up(): void
18:     {
19:         Schema::create((new SalesModeration())->getTable(), function (Blueprint $table) {
20:             $table->id();
21:             $table->foreignId('moderated_by_id')->nullable()->constrained((new User())->getTable());
22:             $table->foreignId('seller_id')->constrained((new Seller())->getTable())->onDelete('cascade');
23:             $table->morphs('moderatable');
24:             $table->string('status', 16);
25:             $table->text('moderation_reason')->nullable();
26:             $table->json('moderation_data')->nullable();
27:             $table->timestamp('moderated_at')->nullable();
28:             $table->timestamps();
29:         });
30:     }
31: 
32:     /**
33:      * Reverse the migrations.
34:      */
35:     public function down(): void
36:     {
37:         Schema::dropIfExists((new SalesModeration())->getTable());
38:     }
39: };
```
