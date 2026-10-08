# Unbox models — numbered source snapshot

HEAD `4e0d338c5316bb5e216239c4bfb0b62ce60c5a88`. Read-only evidence; filenames and line numbers are original.

## Modules/Buyer/app/Enums/Order/PaymentMethod.php

SHA-256 `c78277b44206863ba9a7fff570086716631f2ce23af0ff79832e5fa2ffaadf01`; 22 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Buyer\Enums\Order;
6: 
7: use Filament\Support\Contracts\HasLabel;
8: 
9: enum PaymentMethod: string implements HasLabel
10: {
11:     case Cash = 'cash';
12: 
13:     case Card = 'card';
14: 
15:     public function getLabel(): string
16:     {
17:         return match ($this) {
18:             self::Cash => 'Наличными',
19:             self::Card => 'Картой',
20:         };
21:     }
22: }
```

## Modules/Buyer/app/Enums/Order/PaymentStatus.php

SHA-256 `d07f77e2ead6c9897d5f407dd6216c2cc0e254022cb78747cf12100672301cd4`; 25 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Buyer\Enums\Order;
6: 
7: use Filament\Support\Contracts\HasLabel;
8: 
9: enum PaymentStatus: string implements HasLabel
10: {
11:     case Waiting = 'waiting';
12: 
13:     case Success = 'success';
14: 
15:     case Failed = 'failed';
16: 
17:     public function getLabel(): string
18:     {
19:         return match ($this) {
20:             self::Waiting => 'Ожидает оплаты',
21:             self::Success => 'Успешно',
22:             self::Failed => 'Неуспешно',
23:         };
24:     }
25: }
```

## Modules/Buyer/app/Enums/Order/RefundStatus.php

SHA-256 `4eeb95f6413ebc93c4e260cdf26a80fb96cf0e37979e55a53df4ccb7455c6407`; 25 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Buyer\Enums\Order;
6: 
7: use Filament\Support\Contracts\HasLabel;
8: 
9: enum RefundStatus: string implements HasLabel
10: {
11:     case Pending = 'pending';
12: 
13:     case Approved = 'approved';
14: 
15:     case Rejected = 'rejected';
16: 
17:     public function getLabel(): string
18:     {
19:         return match ($this) {
20:             self::Pending => 'Ожидает рассмотрения',
21:             self::Approved => 'Одобрен',
22:             self::Rejected => 'Отклонен',
23:         };
24:     }
25: }
```

## Modules/Buyer/app/Enums/Order/Status.php

SHA-256 `301e4cafa712b1c8891c689fc657df33ee3a06a82cee8f26bff185587586471c`; 34 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Buyer\Enums\Order;
6: 
7: use Filament\Support\Contracts\HasLabel;
8: 
9: enum Status: string implements HasLabel
10: {
11:     case Pending = 'pending';
12: 
13:     case Paid = 'paid';
14: 
15:     case Processing = 'processing';
16: 
17:     case Shipped = 'shipped';
18: 
19:     case Delivered = 'delivered';
20: 
21:     case Cancelled = 'cancelled';
22: 
23:     public function getLabel(): string
24:     {
25:         return match ($this) {
26:             self::Pending => 'Ожидает оплаты',
27:             self::Paid => 'Оплачен',
28:             self::Processing => 'Обрабатывается',
29:             self::Shipped => 'Отправлен',
30:             self::Delivered => 'Доставлен',
31:             self::Cancelled => 'Отменен',
32:         };
33:     }
34: }
```

## Modules/Buyer/app/Enums/Order/UpdateAction.php

SHA-256 `e29dc883e67d454658234e75a8418377541da43d18a25a0d3a5185a8baf74f31`; 19 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Buyer\Enums\Order;
6: 
7: use Filament\Support\Contracts\HasLabel;
8: 
9: enum UpdateAction: string implements HasLabel
10: {
11:     case Create = 'create';
12: 
13:     public function getLabel(): string
14:     {
15:         return match ($this) {
16:             self::Create => 'Создан',
17:         };
18:     }
19: }
```

## Modules/Buyer/app/Models/Order/Customer.php

SHA-256 `0dee077d77e4623c7db73e14853486cddff703c4429bfe1d3f399cd707ef1225`; 97 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Buyer\Models\Order;
6: 
7: use App\Models\User;
8: use Illuminate\Database\Eloquent\Model;
9: use Illuminate\Database\Eloquent\Relations\BelongsTo;
10: use Illuminate\Database\Eloquent\Relations\HasMany;
11: use Illuminate\Database\Eloquent\SoftDeletes;
12: 
13: /**
14:  * @property int $id
15:  * @property int $user_id
16:  * @property string $first_name
17:  * @property string|null $middle_name
18:  * @property string $last_name
19:  * @property string|null $email
20:  * @property string|null $phone
21:  * @property string|null $comment
22:  * @property \Carbon\CarbonImmutable|null $created_at
23:  * @property \Carbon\CarbonImmutable|null $updated_at
24:  * @property-read User $user
25:  * @property-read \Illuminate\Database\Eloquent\Collection<int, Order> $orders
26:  * @property-read int|null $orders_count
27:  *
28:  * @method static \Database\Factories\Order\CustomerFactory factory($count = null, $state = [])
29:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer newModelQuery()
30:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer newQuery()
31:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer query()
32:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereComment($value)
33:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereCreatedAt($value)
34:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereEmail($value)
35:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereFirstName($value)
36:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereId($value)
37:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereLastName($value)
38:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereMiddleName($value)
39:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer wherePhone($value)
40:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereUpdatedAt($value)
41:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereUserId($value)
42:  *
43:  * @mixin \Illuminate\Database\Eloquent\Model
44:  */
45: final class Customer extends Model
46: {
47:     use SoftDeletes;
48: 
49:     protected $table = 'buyer_order_customers';
50: 
51:     /**
52:      * The attributes that are mass assignable.
53:      *
54:      * @var list<string>
55:      */
56:     protected $fillable = [
57:         'user_id',
58:         'first_name',
59:         'middle_name',
60:         'last_name',
61:         'email',
62:         'phone',
63:         'comment',
64:     ];
65: 
66:     /**
67:      * Get the user that owns this customer.
68:      */
69:     public function user(): BelongsTo
70:     {
71:         return $this->belongsTo(User::class);
72:     }
73: 
74:     /**
75:      * Get the orders for this customer.
76:      */
77:     public function orders(): HasMany
78:     {
79:         return $this->hasMany(Order::class);
80:     }
81: 
82:     /**
83:      * Get the customer's full name.
84:      */
85:     public function getFullNameAttribute(): string
86:     {
87:         $name = $this->first_name;
88: 
89:         if ($this->middle_name) {
90:             $name .= ' '.$this->middle_name;
91:         }
92: 
93:         $name .= ' '.$this->last_name;
94: 
95:         return $name;
96:     }
97: }
```

## Modules/Buyer/app/Models/Order/Item.php

SHA-256 `29cf10021a8e833785175ee799b58e33aa4ffeb536be5186b9d4e60f2fc60b90`; 99 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Buyer\Models\Order;
6: 
7: use Catalog\Models\Product\Product;
8: use Illuminate\Database\Eloquent\Model;
9: use Illuminate\Database\Eloquent\Relations\BelongsTo;
10: 
11: /**
12:  * @property int $id
13:  * @property int $order_id
14:  * @property int $product_id
15:  * @property int $offer_id
16:  * @property json $offer_properties
17:  * @property int $quantity
18:  * @property float $unit_price
19:  * @property float $total_price
20:  * @property float $discount_amount
21:  * @property float $tax_amount
22:  * @property string|null $comment
23:  * @property \Carbon\CarbonImmutable|null $created_at
24:  * @property \Carbon\CarbonImmutable|null $updated_at
25:  * @property-read Order $order
26:  * @property-read Product $product
27:  *
28:  * @method static \Database\Factories\Order\ItemFactory factory($count = null, $state = [])
29:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Item newModelQuery()
30:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Item newQuery()
31:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Item query()
32:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Item whereComment($value)
33:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Item whereCreatedAt($value)
34:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Item whereDiscountAmount($value)
35:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Item whereId($value)
36:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Item whereOrderId($value)
37:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Item whereQuantity($value)
38:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Item whereProductId($value)
39:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Item whereTaxAmount($value)
40:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Item whereTotalPrice($value)
41:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Item whereUnitPrice($value)
42:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Item whereUpdatedAt($value)
43:  *
44:  * @mixin \Illuminate\Database\Eloquent\Model
45:  */
46: final class Item extends Model
47: {
48:     protected $table = 'buyer_order_items';
49: 
50:     /**
51:      * The attributes that are mass assignable.
52:      *
53:      * @var list<string>
54:      */
55:     protected $fillable = [
56:         'order_id',
57:         'product_id',
58:         'offer_id',
59:         'offer_properties',
60:         'quantity',
61:         'unit_price',
62:         'total_price',
63:         'discount_amount',
64:         'tax_amount',
65:         'comment',
66:     ];
67: 
68:     /**
69:      * Get the order that owns this item.
70:      */
71:     public function order(): BelongsTo
72:     {
73:         return $this->belongsTo(Order::class);
74:     }
75: 
76:     /**
77:      * Get the product for this item.
78:      */
79:     public function product(): BelongsTo
80:     {
81:         return $this->belongsTo(Product::class, 'product_id');
82:     }
83: 
84:     /**
85:      * The attributes that should be cast.
86:      *
87:      * @return array<string, string>
88:      */
89:     protected function casts(): array
90:     {
91:         return [
92:             'quantity' => 'integer',
93:             'unit_price' => 'decimal:2',
94:             'total_price' => 'decimal:2',
95:             'discount_amount' => 'decimal:2',
96:             'tax_amount' => 'decimal:2',
97:         ];
98:     }
99: }
```

## Modules/Buyer/app/Models/Order/Order.php

SHA-256 `a7962c30bf1aab7a40b17cfbea011269711b8ee2f2300395f17d85c8ec5a7770`; 213 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Buyer\Models\Order;
6: 
7: use Buyer\Enums\Order\PaymentMethod;
8: use Buyer\Enums\Order\PaymentStatus;
9: use Buyer\Enums\Order\Status;
10: use App\Models\Geo\Address;
11: use App\Models\User;
12: use Illuminate\Database\Eloquent\Model;
13: use Illuminate\Database\Eloquent\Relations\BelongsTo;
14: use Illuminate\Database\Eloquent\Relations\HasMany;
15: use Sales\Models\Shop\Shop;
16: 
17: /**
18:  * @property int $id
19:  * @property int $user_id
20:  * @property int $shop_id
21:  * @property int $customer_id
22:  * @property int $delivery_address_id
23:  * @property string $order_number
24:  * @property Status $status
25:  * @property PaymentMethod $payment_method
26:  * @property PaymentStatus $payment_status
27:  * @property float $gift_card_amount
28:  * @property float $total_price
29:  * @property float $total_discount
30:  * @property float $total_tax
31:  * @property float $total_shipping
32:  * @property float $total_shipping_discount
33:  * @property float $total_shipping_tax
34:  * @property string|null $tracking_number
35:  * @property \Carbon\CarbonImmutable|null $pickup_date
36:  * @property string|null $comment
37:  * @property \Carbon\CarbonImmutable|null $created_at
38:  * @property \Carbon\CarbonImmutable|null $updated_at
39:  * @property-read User $user
40:  * @property-read Shop $shop
41:  * @property-read Customer $customer
42:  * @property-read Address $deliveryAddress
43:  * @property-read \Illuminate\Database\Eloquent\Collection<int, Item> $items
44:  * @property-read int|null $items_count
45:  *
46:  * @method static \Database\Factories\Order\OrderFactory factory($count = null, $state = [])
47:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Order newModelQuery()
48:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Order newQuery()
49:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Order query()
50:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereComment($value)
51:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCreatedAt($value)
52:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCustomerId($value)
53:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereDeliveryAddressId($value)
54:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereGiftCardAmount($value)
55:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereId($value)
56:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereOrderNumber($value)
57:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Order wherePaymentStatus($value)
58:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Order wherePaymentType($value)
59:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Order wherePickupDate($value)
60:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereShopId($value)
61:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereStatus($value)
62:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereTotalDiscount($value)
63:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereTotalPrice($value)
64:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereTotalShipping($value)
65:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereTotalShippingDiscount($value)
66:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereTotalShippingTax($value)
67:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereTotalTax($value)
68:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereTrackingNumber($value)
69:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereUpdatedAt($value)
70:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereUserId($value)
71:  *
72:  * @mixin \Illuminate\Database\Eloquent\Model
73:  */
74: final class Order extends Model
75: {
76:     protected $table = 'buyer_orders';
77: 
78:     /**
79:      * The attributes that are mass assignable.
80:      *
81:      * @var list<string>
82:      */
83:     protected $fillable = [
84:         'user_id',
85:         'shop_id',
86:         'customer_id',
87:         'delivery_address_id',
88:         'order_number',
89:         'status',
90:         'payment_method',
91:         'payment_status',
92:         'gift_card_amount',
93:         'total_price',
94:         'total_discount',
95:         'total_tax',
96:         'total_shipping',
97:         'total_shipping_discount',
98:         'total_shipping_tax',
99:         'tracking_number',
100:         'pickup_date',
101:         'comment',
102:     ];
103: 
104:     /**
105:      * Get the user that owns this order.
106:      */
107:     public function user(): BelongsTo
108:     {
109:         return $this->belongsTo(User::class);
110:     }
111: 
112:     /**
113:      * Get the shop for this order.
114:      */
115:     public function shop(): BelongsTo
116:     {
117:         return $this->belongsTo(Shop::class);
118:     }
119: 
120:     /**
121:      * Get the customer for this order.
122:      */
123:     public function customer(): BelongsTo
124:     {
125:         return $this->belongsTo(Customer::class, 'customer_id');
126:     }
127: 
128:     /**
129:      * Get the delivery address for this order.
130:      */
131:     public function deliveryAddress(): BelongsTo
132:     {
133:         return $this->belongsTo(Address::class, 'delivery_address_id');
134:     }
135: 
136:     /**
137:      * Get the items for this order.
138:      */
139:     public function items(): HasMany
140:     {
141:         return $this->hasMany(Item::class);
142:     }
143: 
144:     /**
145:      * Get the refund requests for this order.
146:      */
147:     public function refundRequests(): HasMany
148:     {
149:         return $this->hasMany(RefundRequest::class);
150:     }
151: 
152:     /**
153:      * Get the updates for this order.
154:      */
155:     public function updates(): HasMany
156:     {
157:         return $this->hasMany(OrderUpdate::class);
158:     }
159: 
160:     /**
161:      * Scope a query to only include orders with a specific status.
162:      */
163:     public function scopeStatus($query, string $status)
164:     {
165:         return $query->where('status', $status);
166:     }
167: 
168:     /**
169:      * Scope a query to only include orders with a specific payment status.
170:      */
171:     public function scopePaymentStatus($query, string $paymentStatus)
172:     {
173:         return $query->where('payment_status', $paymentStatus);
174:     }
175: 
176:     /**
177:      * Scope a query to only include orders for a specific shop.
178:      */
179:     public function scopeForShop($query, int $shopId)
180:     {
181:         return $query->where('shop_id', $shopId);
182:     }
183: 
184:     /**
185:      * Scope a query to only include orders for a specific user.
186:      */
187:     public function scopeForUser($query, int $userId)
188:     {
189:         return $query->where('user_id', $userId);
190:     }
191: 
192:     /**
193:      * The attributes that should be cast.
194:      *
195:      * @return array<string, string>
196:      */
197:     protected function casts(): array
198:     {
199:         return [
200:             'gift_card_amount' => 'decimal:2',
201:             'total_price' => 'decimal:2',
202:             'total_discount' => 'decimal:2',
203:             'total_tax' => 'decimal:2',
204:             'total_shipping' => 'decimal:2',
205:             'total_shipping_discount' => 'decimal:2',
206:             'total_shipping_tax' => 'decimal:2',
207:             'payment_method' => PaymentMethod::class,
208:             'payment_status' => PaymentStatus::class,
209:             'status' => Status::class,
210:             'pickup_date' => 'datetime',
211:         ];
212:     }
213: }
```

## Modules/Buyer/app/Models/Order/OrderUpdate.php

SHA-256 `36fb04a27603893896b4eb8e3a917e6e0e271ada2b0d71c63056f548d1989d0b`; 87 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Buyer\Models\Order;
6: 
7: use Buyer\Enums\Order\UpdateAction;
8: use App\Models\User;
9: use Illuminate\Database\Eloquent\Model;
10: use Illuminate\Database\Eloquent\Relations\BelongsTo;
11: 
12: /**
13:  * @property int $id
14:  * @property int $order_id
15:  * @property int $user_id
16:  * @property UpdateAction $action
17:  * @property array $data
18:  * @property \Carbon\CarbonImmutable|null $created_at
19:  * @property \Carbon\CarbonImmutable|null $updated_at
20:  * @property-read Order $order
21:  * @property-read User $user
22:  *
23:  * @method static \Database\Factories\Order\OrderUpdateFactory factory($count = null, $state = [])
24:  * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUpdate newModelQuery()
25:  * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUpdate newQuery()
26:  * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUpdate query()
27:  * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUpdate whereCreatedAt($value)
28:  * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUpdate whereId($value)
29:  * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUpdate whereOrderId($value)
30:  * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUpdate whereUserId($value)
31:  * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUpdate whereAction($value)
32:  * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUpdate whereData($value)
33:  * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUpdate whereUpdatedAt($value)
34:  *
35:  * @mixin \Illuminate\Database\Eloquent\Model
36:  */
37: final class OrderUpdate extends Model
38: {
39:     public $timestamps = false;
40: 
41:     protected $table = 'buyer_order_updates';
42: 
43:     /**
44:      * The attributes that are mass assignable.
45:      *
46:      * @var list<string>
47:      */
48:     protected $fillable = [
49:         'order_id',
50:         'user_id',
51:         'action',
52:         'data',
53:         'date_added',
54:     ];
55: 
56:     /**
57:      * Get the order that owns this update.
58:      */
59:     public function order(): BelongsTo
60:     {
61:         return $this->belongsTo(Order::class);
62:     }
63: 
64:     /**
65:      * Get the user that owns this update.
66:      */
67:     public function user(): BelongsTo
68:     {
69:         return $this->belongsTo(User::class);
70:     }
71: 
72:     /**
73:      * Scope a query to only include updates with a specific status.
74:      */
75:     public function scopeAction($query, UpdateAction $action)
76:     {
77:         return $query->where('action', $action);
78:     }
79: 
80:     protected function casts(): array
81:     {
82:         return [
83:             'action' => UpdateAction::class,
84:             'data' => 'array',
85:         ];
86:     }
87: }
```

## Modules/Buyer/app/Models/Order/RefundRequest.php

SHA-256 `a398a09ad6819bb309a0368fad45966ffe05772adde751b2a89cffc493b991f1`; 104 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Buyer\Models\Order;
6: 
7: use App\Models\User;
8: use Buyer\Enums\Order\RefundStatus;
9: use Illuminate\Database\Eloquent\Model;
10: use Illuminate\Database\Eloquent\Relations\BelongsTo;
11: 
12: /**
13:  * @property int $id
14:  * @property int $order_id
15:  * @property int $user_id
16:  * @property RefundStatus $status
17:  * @property string|null $reason
18:  * @property float $amount
19:  * @property float $shipping_cost
20:  * @property float $total_cost
21:  * @property float $total_discount
22:  * @property \Carbon\CarbonImmutable|null $created_at
23:  * @property \Carbon\CarbonImmutable|null $updated_at
24:  * @property-read Order $order
25:  * @property-read User $user
26:  *
27:  * @method static \Database\Factories\Order\RefundRequestFactory factory($count = null, $state = [])
28:  * @method static \Illuminate\Database\Eloquent\Builder<static>|RefundRequest newModelQuery()
29:  * @method static \Illuminate\Database\Eloquent\Builder<static>|RefundRequest newQuery()
30:  * @method static \Illuminate\Database\Eloquent\Builder<static>|RefundRequest query()
31:  * @method static \Illuminate\Database\Eloquent\Builder<static>|RefundRequest whereAmount($value)
32:  * @method static \Illuminate\Database\Eloquent\Builder<static>|RefundRequest whereCreatedAt($value)
33:  * @method static \Illuminate\Database\Eloquent\Builder<static>|RefundRequest whereId($value)
34:  * @method static \Illuminate\Database\Eloquent\Builder<static>|RefundRequest whereOrderId($value)
35:  * @method static \Illuminate\Database\Eloquent\Builder<static>|RefundRequest whereReason($value)
36:  * @method static \Illuminate\Database\Eloquent\Builder<static>|RefundRequest whereShippingCost($value)
37:  * @method static \Illuminate\Database\Eloquent\Builder<static>|RefundRequest whereStatus($value)
38:  * @method static \Illuminate\Database\Eloquent\Builder<static>|RefundRequest whereTotalCost($value)
39:  * @method static \Illuminate\Database\Eloquent\Builder<static>|RefundRequest whereTotalDiscount($value)
40:  * @method static \Illuminate\Database\Eloquent\Builder<static>|RefundRequest whereUpdatedAt($value)
41:  * @method static \Illuminate\Database\Eloquent\Builder<static>|RefundRequest whereUserId($value)
42:  *
43:  * @mixin \Illuminate\Database\Eloquent\Model
44:  */
45: final class RefundRequest extends Model
46: {
47:     protected $table = 'buyer_order_refund_requests';
48: 
49:     /**
50:      * The attributes that are mass assignable.
51:      *
52:      * @var list<string>
53:      */
54:     protected $fillable = [
55:         'order_id',
56:         'user_id',
57:         'status',
58:         'reason',
59:         'amount',
60:         'shipping_cost',
61:         'total_cost',
62:         'total_discount',
63:     ];
64: 
65:     /**
66:      * Get the order that owns this refund request.
67:      */
68:     public function order(): BelongsTo
69:     {
70:         return $this->belongsTo(Order::class);
71:     }
72: 
73:     /**
74:      * Get the user that owns this refund request.
75:      */
76:     public function user(): BelongsTo
77:     {
78:         return $this->belongsTo(User::class);
79:     }
80: 
81:     /**
82:      * Scope a query to only include refund requests with a specific status.
83:      */
84:     public function scopeStatus($query, RefundStatus $status)
85:     {
86:         return $query->where('status', $status);
87:     }
88: 
89:     /**
90:      * The attributes that should be cast.
91:      *
92:      * @return array<string, string>
93:      */
94:     protected function casts(): array
95:     {
96:         return [
97:             'status' => RefundStatus::class,
98:             'amount' => 'decimal:2',
99:             'shipping_cost' => 'decimal:2',
100:             'total_cost' => 'decimal:2',
101:             'total_discount' => 'decimal:2',
102:         ];
103:     }
104: }
```

## Modules/Catalog/app/Enums/Product/BlockType.php

SHA-256 `7ace5f2317644bc2b4496cc7a08dcb093e8af1b1cdbf735a541b63d20f9216a7`; 23 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Enums\Product;
6: 
7: use Filament\Support\Contracts\HasLabel;
8: 
9: enum BlockType: string implements HasLabel
10: {
11:     case TEXT = 'text';
12:     case IMAGE = 'image';
13:     case VIDEO = 'video';
14: 
15:     public function getLabel(): string
16:     {
17:         return match ($this) {
18:             self::TEXT => 'Текст',
19:             self::IMAGE => 'Изображение',
20:             self::VIDEO => 'Видео',
21:         };
22:     }
23: }
```

## Modules/Catalog/app/Enums/Product/Condition.php

SHA-256 `a892e34a98b7631831250f01dbf5eab54e1255ffd94834f94cb2bd5063aa8f2b`; 22 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Enums\Product;
6: 
7: use Filament\Support\Contracts\HasLabel;
8: 
9: enum Condition: string implements HasLabel
10: {
11:     case New = 'new';
12: 
13:     case PreOwned  = 'pre_owned';
14: 
15:     public function getLabel(): string
16:     {
17:         return match ($this) {
18:             self::New => 'NEW',
19:             self::PreOwned => 'PRE-OWNED',
20:         };
21:     }
22: }
```

## Modules/Catalog/app/Enums/Product/PropertyControl.php

SHA-256 `94fbaa091a471b524827e325c5caf4f1146941680094dabd084ecdb1f860a909`; 31 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Enums\Product;
6: 
7: use Filament\Support\Contracts\HasLabel;
8: 
9: enum PropertyControl: string implements HasLabel
10: {
11:     case Input = 'input';
12: 
13:         // case Textarea = 'textarea';
14: 
15:     case Select = 'select';
16: 
17:     case Colorpicker = 'colorpicker';
18: 
19:     // case Checkbox = 'checkbox';
20: 
21:     public function getLabel(): string
22:     {
23:         return match ($this) {
24:             self::Input => 'Текст',
25:             // self::Textarea => 'Текстовая область',
26:             self::Select => 'Список',
27:             self::Colorpicker => 'Цвета',
28:             // self::Checkbox => 'Чекбокс',
29:         };
30:     }
31: }
```

## Modules/Catalog/app/Enums/Product/PropertyType.php

SHA-256 `1ca7899a652b45965347ba1e621a5f32fc11861bda27c7dafe189b0402b20240`; 28 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Enums\Product;
6: 
7: use Filament\Support\Contracts\HasLabel;
8: 
9: enum PropertyType: string implements HasLabel
10: {
11:     // case Boolean = 'boolean';
12: 
13:     case Text = 'text';
14: 
15:     case Integer = 'integer';
16: 
17:     case Decimal = 'decimal';
18: 
19:     public function getLabel(): string
20:     {
21:         return match ($this) {
22:             self::Text => 'Текст',
23:             self::Integer => 'Целое число',
24:             self::Decimal => 'Десятичное число',
25:             // self::Boolean => 'Булево',
26:         };
27:     }
28: }
```

## Modules/Catalog/app/Enums/Product/PropertyUnit.php

SHA-256 `4d479972042ac83fbde5d75e40541023811f4ded3c66bd3077a1da21a66b7c65`; 19 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Enums\Product;
6: 
7: use Filament\Support\Contracts\HasLabel;
8: 
9: enum PropertyUnit: string implements HasLabel
10: {
11:     case Unit = 'unit';
12: 
13:     public function getLabel(): string
14:     {
15:         return match ($this) {
16:             self::Unit => 'Штука',
17:         };
18:     }
19: }
```

## Modules/Catalog/app/Enums/Product/Status.php

SHA-256 `227baf6c23305d72a72e50bb356f709bcdc92811acf9ea07f4c8928581aa6f15`; 25 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Enums\Product;
6: 
7: use Filament\Support\Contracts\HasLabel;
8: 
9: enum Status: string implements HasLabel
10: {
11:     case Draft = 'draft';
12:     case Moderation = 'moderation';
13:     case Rejected = 'rejected';
14:     case Active = 'active';
15: 
16:     public function getLabel(): string
17:     {
18:         return match ($this) {
19:             self::Draft => 'Черновик',
20:             self::Moderation => 'На согласовании',
21:             self::Rejected => 'Отклонен',
22:             self::Active => 'Активен',
23:         };
24:     }
25: }
```

## Modules/Catalog/app/Enums/Product/Tag.php

SHA-256 `50b565883d1357e40fd9c02f90d460633abd913e16cbf7fd4032b6d273c78be5`; 21 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Enums\Product;
6: 
7: use Filament\Support\Contracts\HasLabel;
8: 
9: enum Tag: string implements HasLabel
10: {
11:     case Vintage = 'vintage';
12:     case Travels = 'travels';
13: 
14:     public function getLabel(): string
15:     {
16:         return match ($this) {
17:             self::Vintage => 'Винтаж',
18:             self::Travels => 'Путешествия',
19:         };
20:     }
21: }
```

## Modules/Catalog/app/Models/Brand.php

SHA-256 `9154e66c25e3c201cd4c28a26f67c22c3efe8478ff0a67aa4d3daf30d4689fdf`; 58 lines.

```text
1: <?php
2: 
3: namespace Catalog\Models;
4: 
5: use Illuminate\Database\Eloquent\Model;
6: use Illuminate\Database\Eloquent\Relations\HasMany;
7: use Catalog\Models\Product\Product;
8: use Spatie\MediaLibrary\HasMedia;
9: use Spatie\MediaLibrary\InteractsWithMedia;
10: use Illuminate\Database\Eloquent\Factories\HasFactory;
11: use Illuminate\Database\Eloquent\SoftDeletes;
12: use Spatie\Sluggable\HasSlug;
13: use Spatie\Sluggable\SlugOptions;
14: 
15: class Brand extends Model implements HasMedia
16: {
17:     use HasFactory, HasSlug, InteractsWithMedia, SoftDeletes;
18: 
19:     /**
20:      * The table associated with the model.
21:      */
22:     protected $table = 'cat_brands';
23: 
24:     /**
25:      * The path to the media files.
26:      */
27:     public string $mediaPath = 'cat/brands';
28: 
29:     /**
30:      * The attributes that are mass assignable.
31:      *
32:      * @var list<string>
33:      */
34:     protected $fillable = [
35:         'name',
36:         'slug',
37:         'description',
38:         'sort_order',
39:     ];
40: 
41:     /**
42:      * Get the options for generating the slug.
43:      */
44:     public function getSlugOptions(): SlugOptions
45:     {
46:         return SlugOptions::create()
47:             ->generateSlugsFrom('name')
48:             ->saveSlugsTo('slug');
49:     }
50: 
51:     /**
52:      * Get the products for the brand.
53:      */
54:     public function products(): HasMany
55:     {
56:         return $this->hasMany(Product::class, 'brand_id');
57:     }
58: }
```

## Modules/Catalog/app/Models/Category.php

SHA-256 `7002ae1bc5166e89fa824772dc57075f3d46df9a1a3126d0535d724439378f29`; 199 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Models;
6: 
7: use App\Models\User;
8: use Illuminate\Database\Eloquent\Factories\HasFactory;
9: use Illuminate\Database\Eloquent\Model;
10: use Catalog\Models\Product\Product;
11: use Catalog\Models\Product\ProductCategory;
12: use Illuminate\Database\Eloquent\Relations\BelongsTo;
13: use Illuminate\Database\Eloquent\Relations\BelongsToMany;
14: use Illuminate\Database\Eloquent\Relations\HasMany;
15: use Illuminate\Database\Eloquent\SoftDeletes;
16: use Spatie\MediaLibrary\HasMedia;
17: use Spatie\MediaLibrary\InteractsWithMedia;
18: use Spatie\Sluggable\HasSlug;
19: use Spatie\Sluggable\SlugOptions;
20: 
21: /**
22:  * @property int $id
23:  * @property int|null $suggested_by_id
24:  * @property int|null $parent_id
25:  * @property string $name
26:  * @property string $slug
27:  * @property string|null $description
28:  * @property int $sort_order
29:  * @property bool $is_active
30:  * @property \Carbon\CarbonImmutable|null $verified_at
31:  * @property int|null $verified_by_id
32:  * @property \Carbon\CarbonImmutable|null $created_at
33:  * @property \Carbon\CarbonImmutable|null $updated_at
34:  * @property \Carbon\CarbonImmutable|null $deleted_at
35:  * @property-read User|null $suggestedBy
36:  * @property-read CatalogCategory|null $parent
37:  * @property-read \Illuminate\Database\Eloquent\Collection<int, CatalogCategory> $children
38:  * @property-read int|null $children_count
39:  * @property-read User|null $verifiedBy
40:  *
41:  * @method static \Catalog\Database\Factories\CategoryFactory factory($count = null, $state = [])
42:  * @method static \Illuminate\Database\Eloquent\Builder<static>|CatalogCategory newModelQuery()
43:  * @method static \Illuminate\Database\Eloquent\Builder<static>|CatalogCategory newQuery()
44:  * @method static \Illuminate\Database\Eloquent\Builder<static>|CatalogCategory query()
45:  * @method static \Illuminate\Database\Eloquent\Builder<static>|CatalogCategory whereCreatedAt($value)
46:  * @method static \Illuminate\Database\Eloquent\Builder<static>|CatalogCategory whereDeletedAt($value)
47:  * @method static \Illuminate\Database\Eloquent\Builder<static>|CatalogCategory whereDescription($value)
48:  * @method static \Illuminate\Database\Eloquent\Builder<static>|CatalogCategory whereId($value)
49:  * @method static \Illuminate\Database\Eloquent\Builder<static>|CatalogCategory whereIsActive($value)
50:  * @method static \Illuminate\Database\Eloquent\Builder<static>|CatalogCategory whereName($value)
51:  * @method static \Illuminate\Database\Eloquent\Builder<static>|CatalogCategory whereParentId($value)
52:  * @method static \Illuminate\Database\Eloquent\Builder<static>|CatalogCategory whereSlug($value)
53:  * @method static \Illuminate\Database\Eloquent\Builder<static>|CatalogCategory whereSortOrder($value)
54:  * @method static \Illuminate\Database\Eloquent\Builder<static>|CatalogCategory whereSuggestedById($value)
55:  * @method static \Illuminate\Database\Eloquent\Builder<static>|CatalogCategory whereUpdatedAt($value)
56:  * @method static \Illuminate\Database\Eloquent\Builder<static>|CatalogCategory whereVerifiedAt($value)
57:  * @method static \Illuminate\Database\Eloquent\Builder<static>|CatalogCategory whereVerifiedById($value)
58:  *
59:  * @mixin \Illuminate\Database\Eloquent\Model
60:  */
61: final class Category extends Model implements HasMedia
62: {
63:     use HasFactory, HasSlug, InteractsWithMedia, SoftDeletes;
64: 
65:     public const MEDIA_COLLECTION = 'category';
66: 
67:     public const MEDIA_DECORS_COLLECTION = 'category-decors';
68: 
69:     /**
70:      * The path to the media files.
71:      */
72:     public string $mediaPath = 'cat/categories';
73: 
74:     /**
75:      * The table associated with the model.
76:      */
77:     protected $table = 'cat_categories';
78: 
79:     /**
80:      * The attributes that are mass assignable.
81:      *
82:      * @var list<string>
83:      */
84:     protected $fillable = [
85:         'suggested_by_id',
86:         'parent_id',
87:         'name',
88:         'slug',
89:         'description',
90:         'sort_order',
91:         'is_active',
92:         'verified_at',
93:         'verified_by_id',
94:     ];
95: 
96:     /**
97:      * Get the options for generating the slug.
98:      */
99:     public function getSlugOptions(): SlugOptions
100:     {
101:         return SlugOptions::create()
102:             ->generateSlugsFrom('name')
103:             ->saveSlugsTo('slug');
104:     }
105: 
106:     /**
107:      * Get the user who suggested this category.
108:      */
109:     public function suggestedBy(): BelongsTo
110:     {
111:         return $this->belongsTo(User::class, 'suggested_by_id');
112:     }
113: 
114:     /**
115:      * Get the parent category.
116:      */
117:     public function parent(): BelongsTo
118:     {
119:         return $this->belongsTo(self::class, 'parent_id');
120:     }
121: 
122:     /**
123:      * Get the child categories.
124:      */
125:     public function children(): HasMany
126:     {
127:         return $this->hasMany(self::class, 'parent_id');
128:     }
129: 
130:     /**
131:      * Get the products for this category.
132:      */
133:     public function products(): BelongsToMany
134:     {
135:         return $this->belongsToMany(Product::class, (new ProductCategory)->getTable());
136:     }
137: 
138:     /**
139:      * Get the user who verified this category.
140:      */
141:     public function verifiedBy(): BelongsTo
142:     {
143:         return $this->belongsTo(User::class, 'verified_by_id');
144:     }
145: 
146:     /**
147:      * Scope a query to only include active categories.
148:      */
149:     public function scopeActive($query)
150:     {
151:         return $query->where('is_active', true);
152:     }
153: 
154:     /**
155:      * Scope a query to only include verified categories.
156:      */
157:     public function scopeVerified($query)
158:     {
159:         return $query->whereNotNull('verified_at');
160:     }
161: 
162:     /**
163:      * Scope a query to only include root categories (no parent).
164:      */
165:     public function scopeRoot($query)
166:     {
167:         return $query->whereNull('parent_id');
168:     }
169: 
170:     /**
171:      * Scope a query to order by sort order.
172:      */
173:     public function scopeOrdered($query)
174:     {
175:         return $query->orderBy('sort_order');
176:     }
177: 
178:     /**
179:      * Create a new factory instance for the model.
180:      */
181:     protected static function newFactory()
182:     {
183:         return \Catalog\Database\Factories\CategoryFactory::new();
184:     }
185: 
186:     /**
187:      * The attributes that should be cast.
188:      *
189:      * @return array<string, string>
190:      */
191:     protected function casts(): array
192:     {
193:         return [
194:             'sort_order' => 'integer',
195:             'is_active' => 'boolean',
196:             'verified_at' => 'datetime',
197:         ];
198:     }
199: }
```

## Modules/Catalog/app/Models/Collection/Collection.php

SHA-256 `f58517f1b737294fad007d406482d47a73a8835c3b5a5435f3fd416d00fff65a`; 240 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Models\Collection;
6: 
7: use App\Enums\Common\Moderation\ModerationStatus;
8: use App\Models\User;
9: use Catalog\Models\Product\Product;
10: use Catalog\Models\Product\ProductCollection;
11: use Illuminate\Database\Eloquent\Casts\Attribute;
12: use Illuminate\Database\Eloquent\Model;
13: use Illuminate\Database\Eloquent\Relations\BelongsTo;
14: use Illuminate\Database\Eloquent\Relations\BelongsToMany;
15: use Illuminate\Database\Eloquent\Relations\HasMany;
16: use Illuminate\Database\Eloquent\Relations\MorphMany;
17: use Illuminate\Database\Eloquent\SoftDeletes;
18: use Sales\Models\Seller\SalesModeration;
19: use Sales\Models\Shop\Shop;
20: use Spatie\MediaLibrary\HasMedia;
21: use Spatie\MediaLibrary\InteractsWithMedia;
22: use Spatie\Sluggable\HasSlug;
23: use Spatie\Sluggable\SlugOptions;
24: 
25: /**
26:  * @property int $id
27:  * @property int $shop_id
28:  * @property string $name
29:  * @property string $slug
30:  * @property string|null $description
31:  * @property int $sort_order
32:  * @property bool $is_active
33:  * @property bool $verified
34:  * @property json|null $moderation_data
35:  * @property \Carbon\CarbonImmutable|null $created_at
36:  * @property \Carbon\CarbonImmutable|null $updated_at
37:  * @property \Carbon\CarbonImmutable|null $deleted_at
38:  * @property-read Shop $shop
39:  * @property-read \Illuminate\Database\Eloquent\Collection<int, Product> $products
40:  * @property-read int|null $products_count
41:  * @property-read User|null $moderatedBy
42:  *
43:  * @method static \Database\Factories\Shop\CollectionFactory factory($count = null, $state = [])
44:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection newModelQuery()
45:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection newQuery()
46:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection query()
47:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection whereCreatedAt($value)
48:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection whereDeletedAt($value)
49:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection whereDescription($value)
50:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection whereId($value)
51:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection whereIsActive($value)
52:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection whereName($value)
53:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection whereShopId($value)
54:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection whereSlug($value)
55:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection whereSortOrder($value)
56:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection whereUpdatedAt($value)
57:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection whereVerified($value)
58:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection whereModerationData($value)
59:  *
60:  * @mixin \Illuminate\Database\Eloquent\Model
61:  */
62: final class Collection extends Model implements HasMedia
63: {
64:     use HasSlug, InteractsWithMedia, SoftDeletes;
65: 
66:     /**
67:      * Media collection constants.
68:      */
69:     public const MEDIA_COLLECTION = 'images';
70: 
71:     public $mediaPath = 'collections';
72: 
73:     protected $table = 'cat_collections';
74: 
75:     /**
76:      * The attributes that are mass assignable.
77:      *
78:      * @var list<string>
79:      */
80:     protected $fillable = [
81:         'shop_id',
82:         'name',
83:         'slug',
84:         'description',
85:         'subdescription',
86:         'sort_order',
87:         'is_active',
88:         'is_main',
89:         'verified',
90:     ];
91: 
92:     /**
93:      * Get the options for generating the slug.
94:      */
95:     public function getSlugOptions(): SlugOptions
96:     {
97:         return SlugOptions::create()
98:             ->generateSlugsFrom('name')
99:             ->saveSlugsTo('slug');
100:     }
101: 
102:     /**
103:      * Get the shop that owns this collection.
104:      */
105:     public function shop(): BelongsTo
106:     {
107:         return $this->belongsTo(Shop::class);
108:     }
109: 
110:     /**
111:      * Get the products for this collection.
112:      */
113:     public function products(): BelongsToMany
114:     {
115:         return $this->belongsToMany(Product::class, ProductCollection::class);
116:     }
117: 
118:     public function hasTags(): HasMany
119:     {
120:         return $this->hasMany(CollectionHasTag::class);
121:     }
122: 
123:     public function tags(): BelongsToMany
124:     {
125:         return $this->belongsToMany(Tag::class, CollectionHasTag::class, 'collection_id', 'tag_id');
126:     }
127: 
128:     /**
129:      * Get the moderation records for this collection.
130:      */
131:     public function moderations(): MorphMany
132:     {
133:         return $this->morphMany(SalesModeration::class, 'moderatable');
134:     }
135: 
136:     /**
137:      * Scope a query to only include active collections.
138:      */
139:     public function scopeActive($query)
140:     {
141:         return $query->where('is_active', true);
142:     }
143: 
144:     /**
145:      * Scope a query to order by sort order.
146:      */
147:     public function scopeOrdered($query)
148:     {
149:         return $query->orderBy('sort_order');
150:     }
151: 
152:     /**
153:      * Get the latest moderation status.
154:      */
155:     public function getLatestModerationStatusAttribute(): ?ModerationStatus
156:     {
157:         return $this->moderations()->latest()->first()?->status;
158:     }
159: 
160:     /**
161:      * Get formatted moderated date and time (from latest approved moderation).
162:      */
163:     public function moderatedAtFormatted(): Attribute
164:     {
165:         return Attribute::make(
166:             get: function (): string {
167:                 $moderation = $this->relationLoaded('moderations')
168:                     ? $this->moderations->first()
169:                     : $this->moderations()->latest()->first();
170: 
171:                 return $moderation?->moderated_at?->format('d.m.Y H:i') ?? '';
172:             }
173:         );
174:     }
175: 
176:     /**
177:      * Get the user who moderated this collection (from latest moderation).
178:      */
179:     public function getModeratedByAttribute(): ?User
180:     {
181:         $moderation = $this->relationLoaded('moderations')
182:             ? $this->moderations->first()
183:             : $this->moderations()->with('moderatedBy')->latest()->first();
184: 
185:         return $moderation?->moderatedBy;
186:     }
187: 
188:     /**
189:      * Create a new moderation record for this collection.
190:      */
191:     public function createModerationRecord(array $data): void
192:     {
193:         $this->moderations()->create([
194:             'seller_id' => $this->shop->seller_id,
195:             'status' => ModerationStatus::Pending,
196:             'moderation_data' => $data,
197:         ]);
198:     }
199: 
200:     protected static function boot(): void
201:     {
202:         parent::boot();
203: 
204:         self::created(function (Collection $collection) {
205:             $collection->createModerationRecord([
206:                 'name' => $collection->name,
207:                 'description' => $collection->description,
208:             ]);
209:         });
210: 
211:         self::updating(function (Collection $collection) {
212:             $original = $collection->getOriginal();
213: 
214:             // Проверяем, изменились ли поля name или description
215:             $changedFields = array_intersect_key($collection->getDirty(), array_flip(['name', 'description']));
216: 
217:             if (! empty($changedFields)) {
218:                 $collection->createModerationRecord([
219:                     'name' => $collection->name,
220:                     'description' => $collection->description,
221:                 ]);
222:             }
223:         });
224:     }
225: 
226:     /**
227:      * The attributes that should be cast.
228:      *
229:      * @return array<string, string>
230:      */
231:     protected function casts(): array
232:     {
233:         return [
234:             'sort_order' => 'integer',
235:             'is_active' => 'boolean',
236:             'is_main' => 'boolean',
237:             'verified' => 'boolean',
238:         ];
239:     }
240: }
```

## Modules/Catalog/app/Models/Collection/CollectionBlock.php

SHA-256 `133c285bbe0fd37e3f2d84f9b936e21efa7cb9744e2fdbbcdbe2b9b597ab095c`; 44 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Models\Collection;
6: 
7: use Illuminate\Database\Eloquent\Factories\HasFactory;
8: use Illuminate\Database\Eloquent\Model;
9: use Illuminate\Database\Eloquent\Relations\BelongsTo;
10: use Spatie\MediaLibrary\HasMedia;
11: use Spatie\MediaLibrary\InteractsWithMedia;
12: 
13: final class CollectionBlock extends Model implements HasMedia
14: {
15:     use HasFactory, InteractsWithMedia;
16: 
17:     /**
18:      * The path to the media files.
19:      */
20:     public string $mediaPath = 'catalog/collection_blocks';
21: 
22:     /**
23:      * The table associated with the model.
24:      */
25:     protected $table = 'cat_collection_blocks';
26: 
27:     /**
28:      * The attributes that are mass assignable.
29:      *
30:      * @var list<string>
31:      */
32:     protected $fillable = [
33:         'collection_id',
34:         'type',
35:         'title',
36:         'content',
37:         'sort_order',
38:     ];
39: 
40:     public function collection(): BelongsTo
41:     {
42:         return $this->belongsTo(Collection::class);
43:     }
44: }
```

## Modules/Catalog/app/Models/Collection/CollectionHasTag.php

SHA-256 `e2b583938edea26c14be9c8f1ef98452e4bbd6bf832ea4a5d5bc582e3d9e90c0`; 49 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Models\Collection;
6: 
7: use Illuminate\Database\Eloquent\Model;
8: use Illuminate\Database\Eloquent\Relations\BelongsTo;
9: 
10: /**
11:  * @property int $id
12:  * @property int $collection_id
13:  * @property int $tag_id
14:  * @property-read Collection $collection
15:  * @property-read Tag $tag
16:  *
17:  * @method static \Illuminate\Database\Eloquent\Builder<static>|CollectionHasTag newModelQuery()
18:  * @method static \Illuminate\Database\Eloquent\Builder<static>|CollectionHasTag newQuery()
19:  * @method static \Illuminate\Database\Eloquent\Builder<static>|CollectionHasTag query()
20:  * @method static \Illuminate\Database\Eloquent\Builder<static>|CollectionHasTag whereCollectionId($value)
21:  * @method static \Illuminate\Database\Eloquent\Builder<static>|CollectionHasTag whereId($value)
22:  * @method static \Illuminate\Database\Eloquent\Builder<static>|CollectionHasTag whereTagId($value)
23:  */
24: final class CollectionHasTag extends Model
25: {
26:     protected $table = 'cat_collection_has_tags';
27: 
28:     public $timestamps = false;
29: 
30:     /**
31:      * The attributes that are mass assignable.
32:      *
33:      * @var list<string>
34:      */
35:     protected $fillable = [
36:         'collection_id',
37:         'tag_id',
38:     ];
39: 
40:     public function collection(): BelongsTo
41:     {
42:         return $this->belongsTo(Collection::class, 'collection_id');
43:     }
44: 
45:     public function tag(): BelongsTo
46:     {
47:         return $this->belongsTo(Tag::class, 'tag_id');
48:     }
49: }
```

## Modules/Catalog/app/Models/Collection/Tag.php

SHA-256 `bf26a12c481295a35178fda6dfa7b3e0e0e5a7486cd1fcb21730aa05031d0368`; 47 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Models\Collection;
6: 
7: use Illuminate\Database\Eloquent\Model;
8: use Illuminate\Database\Eloquent\Relations\BelongsToMany;
9: use Illuminate\Database\Eloquent\SoftDeletes;
10: 
11: final class Tag extends Model
12: {
13:     use SoftDeletes;
14: 
15:     protected $table = 'cat_collection_tags';
16: 
17:     /**
18:      * The attributes that are mass assignable.
19:      *
20:      * @var list<string>
21:      */
22:     protected $fillable = [
23:         'name',
24:         'slug',
25:         'description',
26:         'is_active',
27:         'sort_order',
28:     ];
29: 
30:     public function collections(): BelongsToMany
31:     {
32:         return $this->belongsToMany(Collection::class, 'cat_collection_has_tags', 'tag_id', 'collection_id');
33:     }
34: 
35:     /**
36:      * The attributes that should be cast.
37:      *
38:      * @return array<string, string>
39:      */
40:     protected function casts(): array
41:     {
42:         return [
43:             'is_active' => 'boolean',
44:             'sort_order' => 'integer',
45:         ];
46:     }
47: }
```

## Modules/Catalog/app/Models/Product/Product.php

SHA-256 `f38f6f8e7192d030e3432296d9f3304b7ef33cd13c517e937cb01c7d90cfd5c6`; 376 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Models\Product;
6: 
7: use App\Enums\Common\Moderation\ModerationStatus;
8: use App\Models\User;
9: use Catalog\Enums\Product\Condition;
10: use Catalog\Enums\Product\Status;
11: use Catalog\Models\Brand;
12: use Catalog\Models\Category;
13: use Catalog\Models\Collection\Collection;
14: use Catalog\Models\Property;
15: use Illuminate\Database\Eloquent\Factories\HasFactory;
16: use Illuminate\Database\Eloquent\Model;
17: use Illuminate\Database\Eloquent\Relations\BelongsTo;
18: use Illuminate\Database\Eloquent\Relations\BelongsToMany;
19: use Illuminate\Database\Eloquent\Relations\HasMany;
20: use Illuminate\Database\Eloquent\Relations\MorphMany;
21: use Illuminate\Database\Eloquent\Relations\MorphOne;
22: use Illuminate\Database\Eloquent\SoftDeletes;
23: use Sales\Enums\Shop\InventoryPolicy;
24: use Sales\Models\Seller\SalesModeration;
25: use Sales\Models\Shop\Shop;
26: use Spatie\MediaLibrary\HasMedia;
27: use Spatie\MediaLibrary\InteractsWithMedia;
28: use Spatie\MediaLibrary\MediaCollections\Models\Media;
29: use Spatie\Sluggable\HasSlug;
30: use Spatie\Sluggable\SlugOptions;
31: 
32: /**
33:  * @property int $id
34:  * @property int $shop_id
35:  * @property int $created_by_id
36:  * @property string $name
37:  * @property string $slug
38:  * @property int|null $brand_id
39:  * @property string|null $description
40:  * @property int $sort_order
41:  * @property string $inventory_policy
42:  * @property float|null $price
43:  * @property int|null $base_weight
44:  * @property int|null $base_length
45:  * @property int|null $base_width
46:  * @property int|null $base_height
47:  * @property int $measure_ratio
48:  * @property Condition $condition
49:  * @property bool $is_new
50:  * @property bool $is_sale
51:  * @property bool $is_discount
52:  * @property bool $is_hit
53:  * @property bool $is_active
54:  * @property \Carbon\CarbonImmutable|null $published_at
55:  * @property \Carbon\CarbonImmutable|null $created_at
56:  * @property \Carbon\CarbonImmutable|null $updated_at
57:  * @property \Carbon\CarbonImmutable|null $deleted_at
58:  * @property-read Shop $shop
59:  * @property-read User $createdBy
60:  * @property-read Brand|null $brand
61:  *
62:  * @method static \Catalog\Database\Factories\ProductFactory factory($count = null, $state = [])
63:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product newModelQuery()
64:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product newQuery()
65:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product query()
66:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereBaseHeight($value)
67:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereBaseLength($value)
68:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereBaseWeight($value)
69:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereBaseWidth($value)
70:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereCreatedAt($value)
71:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereCreatedById($value)
72:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereDeletedAt($value)
73:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereDescription($value)
74:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereId($value)
75:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereInventoryPolicy($value)
76:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereIsActive($value)
77:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereIsDiscount($value)
78:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereIsHit($value)
79:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereIsNew($value)
80:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereIsSale($value)
81:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereMeasureRatio($value)
82:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereName($value)
83:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product wherePublishedAt($value)
84:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereShopId($value)
85:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereSlug($value)
86:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereSortOrder($value)
87:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereCondition($value)
88:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereUpdatedAt($value)
89:  *
90:  * @mixin \Illuminate\Database\Eloquent\Model
91:  */
92: final class Product extends Model implements HasMedia
93: {
94:     use HasFactory, HasSlug, InteractsWithMedia, SoftDeletes;
95: 
96:     /**
97:      * The path to the media files.
98:      */
99:     public string $mediaPath = 'cat/products';
100: 
101:     protected $table = 'cat_products';
102: 
103:     public const IMAGES_COLLECTION = 'images';
104: 
105:     public const IMAGE_HOVER_COLLECTION = 'image-hover';
106: 
107:     /**
108:      * The attributes that are mass assignable.
109:      *
110:      * @var list<string>
111:      */
112:     protected $fillable = [
113:         'shop_id',
114:         'created_by_id',
115:         'name',
116:         'subtitle',
117:         'slug',
118:         'brand_id',
119:         'description',
120:         'sort_order',
121:         'inventory_policy',
122:         'price',
123:         'old_price',
124:         'base_weight',
125:         'base_length',
126:         'base_width',
127:         'base_height',
128:         'measure_ratio',
129:         'condition',
130:         'is_new',
131:         'is_sale',
132:         'is_discount',
133:         'is_hit',
134:         'is_active',
135:         'published_at',
136:         'status',
137:     ];
138: 
139:     /**
140:      * Get the options for generating the slug.
141:      */
142:     public function getSlugOptions(): SlugOptions
143:     {
144:         return SlugOptions::create()
145:             ->generateSlugsFrom('name')
146:             ->saveSlugsTo('slug');
147:     }
148: 
149:     /**
150:      * Get the shop that owns this product.
151:      */
152:     public function shop(): BelongsTo
153:     {
154:         return $this->belongsTo(Shop::class);
155:     }
156: 
157:     /**
158:      * Get the user who created this product.
159:      */
160:     public function createdBy(): BelongsTo
161:     {
162:         return $this->belongsTo(User::class, 'created_by_id');
163:     }
164: 
165:     /**
166:      * Get the brand that owns this product.
167:      */
168:     public function brand(): BelongsTo
169:     {
170:         return $this->belongsTo(Brand::class, 'brand_id');
171:     }
172: 
173:     /**
174:      * Get the properties for this product.
175:      */
176:     public function properties(): BelongsToMany
177:     {
178:         return $this->belongsToMany(Property::class, 'cat_product_properties')
179:             ->using(ProductPropertyPivot::class)
180:             ->withPivot('available_codes');
181:     }
182: 
183:     /**
184:      * Get the blocks for this product.
185:      */
186:     public function blocks(): HasMany
187:     {
188:         return $this->hasMany(ProductBlock::class, 'product_id');
189:     }
190: 
191:     /**
192:      * Get the offers for this product.
193:      */
194:     public function offers(): HasMany
195:     {
196:         return $this->hasMany(ProductOffer::class, 'product_id');
197:     }
198: 
199:     /**
200:      * Get the colors for this product.
201:      */
202:     public function colors(): HasMany
203:     {
204:         return $this->hasMany(ProductColor::class, 'product_id');
205:     }
206: 
207:     /**
208:      * Get the collections for this product.
209:      */
210:     public function collections(): BelongsToMany
211:     {
212:         return $this->belongsToMany(Collection::class, (new ProductCollection)->getTable());
213:     }
214: 
215:     public function canChangeProperties(): bool
216:     {
217:         return ! $this->offers()->exists();
218:     }
219: 
220:     /**
221:      * Check if a property is used in any offers of this product.
222:      */
223:     public function isPropertyUsedInOffers(int $propertyId): bool
224:     {
225:         return $this->offers()
226:             ->whereHas('propertyValues', function ($query) use ($propertyId) {
227:                 $query->where('property_id', $propertyId);
228:             })
229:             ->exists();
230:     }
231: 
232:     /**
233:      * Get the categories for this product.
234:      */
235:     public function categories(): BelongsToMany
236:     {
237:         return $this->belongsToMany(Category::class, (new ProductCategory)->getTable());
238:     }
239: 
240:     /**
241:      * Get the specifications for this product.
242:      */
243:     public function specifications(): HasMany
244:     {
245:         return $this->hasMany(ProductSpecificationGroup::class, 'product_id');
246:     }
247: 
248:     /**
249:      * Get the moderation records for this product.
250:      */
251:     public function moderations(): MorphMany
252:     {
253:         return $this->morphMany(SalesModeration::class, 'moderatable');
254:     }
255: 
256:     /**
257:      * Get the moderation current record for this product.
258:      */
259:     public function currentModeration(): MorphOne
260:     {
261:         return $this->morphOne(SalesModeration::class, 'moderatable')
262:             ->latestOfMany();
263:     }
264: 
265:     /**
266:      * Scope a query to only include active products.
267:      */
268:     public function scopeActive($query)
269:     {
270:         return $query->where('is_active', true);
271:     }
272: 
273:     /**
274:      * Scope a query to only include published products.
275:      */
276:     public function scopePublished($query)
277:     {
278:         return $query->whereNotNull('published_at');
279:     }
280: 
281:     /**
282:      * Scope a query to only include new products.
283:      */
284:     public function scopeNew($query)
285:     {
286:         return $query->where('is_new', true);
287:     }
288: 
289:     /**
290:      * Scope a query to only include sale products.
291:      */
292:     public function scopeSale($query)
293:     {
294:         return $query->where('is_sale', true);
295:     }
296: 
297:     /**
298:      * Scope a query to only include hit products.
299:      */
300:     public function scopeHit($query)
301:     {
302:         return $query->where('is_hit', true);
303:     }
304: 
305:     /**
306:      * Scope a query to order by sort order.
307:      */
308:     public function scopeOrdered($query)
309:     {
310:         return $query->orderBy('sort_order');
311:     }
312: 
313:     /**
314:      * Get the latest moderation status.
315:      */
316:     public function getLatestModerationStatusAttribute(): ?ModerationStatus
317:     {
318:         return $this->moderations()->latest()->first()?->status;
319:     }
320: 
321:     /**
322:      * Create a new moderation record for this product.
323:      */
324:     public function createModerationRecord(array $data): void
325:     {
326:         $this->moderations()->create([
327:             'seller_id' => $this->shop->seller_id,
328:             'status' => ModerationStatus::Pending,
329:             'moderation_data' => $data,
330:         ]);
331:     }
332: 
333:     /**
334:      * Register media collections.
335:      */
336:     public function registerMediaCollections(): void
337:     {
338:         $this->addMediaCollection('images')
339:             ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/jpg', 'image/webp']);
340:     }
341: 
342:     /**
343:      * Create a new factory instance for the model.
344:      */
345:     protected static function newFactory()
346:     {
347:         return \Catalog\Database\Factories\ProductFactory::new();
348:     }
349: 
350:     /**
351:      * The attributes that should be cast.
352:      *
353:      * @return array<string, string>
354:      */
355:     protected function casts(): array
356:     {
357:         return [
358:             'inventory_policy' => InventoryPolicy::class,
359:             'condition' => Condition::class,
360:             'price' => 'decimal:2',
361:             'sort_order' => 'integer',
362:             'base_weight' => 'integer',
363:             'base_length' => 'integer',
364:             'base_width' => 'integer',
365:             'base_height' => 'integer',
366:             'measure_ratio' => 'integer',
367:             'is_new' => 'boolean',
368:             'is_sale' => 'boolean',
369:             'is_discount' => 'boolean',
370:             'is_hit' => 'boolean',
371:             'is_active' => 'boolean',
372:             'published_at' => 'date',
373:             'status' => Status::class,
374:         ];
375:     }
376: }
```

## Modules/Catalog/app/Models/Product/ProductBlock.php

SHA-256 `ca6f0a48a4eba6130db4f65a31cb41d9e4270641257cc91147bc8466ee953991`; 36 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Models\Product;
6: 
7: use Catalog\Enums\Product\BlockType;
8: use Illuminate\Database\Eloquent\Model;
9: 
10: final class ProductBlock extends Model
11: {
12:     public $timestamps = false;
13: 
14:     protected $table = 'cat_product_blocks';
15: 
16:     protected $fillable = [
17:         'product_id',
18:         'type',
19:         'title',
20:         'content',
21:         'sort_order',
22:     ];
23: 
24:     public function product()
25:     {
26:         return $this->belongsTo(Product::class, 'product_id');
27:     }
28: 
29:     public function casts()
30:     {
31:         return [
32:             'type' => BlockType::class,
33:             'content' => 'array',
34:         ];
35:     }
36: }
```

## Modules/Catalog/app/Models/Product/ProductCategory.php

SHA-256 `d1c13024285647c4a4415db3ef4560928234f65ad7f2ebca1a19a3431853e7b8`; 57 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Models\Product;
6: 
7: use Catalog\Models\Category;
8: use Illuminate\Database\Eloquent\Model;
9: use Illuminate\Database\Eloquent\Relations\BelongsTo;
10: 
11: /**
12:  * @property int $id
13:  * @property int $product_id
14:  * @property int $category_id
15:  * @property-read Product $product
16:  * @property-read Category $category
17:  *
18:  * @method static \Illuminate\Database\Eloquent\Factories\Factory factory($count = null, $state = [])
19:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory newModelQuery()
20:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory newQuery()
21:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory query()
22:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereCategoryId($value)
23:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereId($value)
24:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereProductId($value)
25:  *
26:  * @mixin \Illuminate\Database\Eloquent\Model
27:  */
28: final class ProductCategory extends Model
29: {
30:     protected $table = 'cat_product_categories';
31: 
32:     /**
33:      * The attributes that are mass assignable.
34:      *
35:      * @var list<string>
36:      */
37:     protected $fillable = [
38:         'product_id',
39:         'category_id',
40:     ];
41: 
42:     /**
43:      * Get the product that owns this category relationship.
44:      */
45:     public function product(): BelongsTo
46:     {
47:         return $this->belongsTo(Product::class, 'product_id');
48:     }
49: 
50:     /**
51:      * Get the category that owns this product relationship.
52:      */
53:     public function category(): BelongsTo
54:     {
55:         return $this->belongsTo(Category::class, 'category_id');
56:     }
57: }
```

## Modules/Catalog/app/Models/Product/ProductCollection.php

SHA-256 `7b9e217f9ba178556d1ec18168475dcd03f188592f43d7421f5abcd07db55ea7`; 60 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Models\Product;
6: 
7: use Illuminate\Database\Eloquent\Factories\HasFactory;
8: use Illuminate\Database\Eloquent\Model;
9: use Illuminate\Database\Eloquent\Relations\BelongsTo;
10: use Catalog\Models\Collection\Collection;
11: 
12: /**
13:  * @property int $id
14:  * @property int $product_id
15:  * @property int $collection_id
16:  * @property-read Product $product
17:  * @property-read Collection $collection
18:  *
19:  * @method static \Seller\Database\Factories\Shop\ProductCollectionFactory factory($count = null, $state = [])
20:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCollection newModelQuery()
21:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCollection newQuery()
22:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCollection query()
23:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCollection whereCollectionId($value)
24:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCollection whereId($value)
25:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCollection whereProductId($value)
26:  *
27:  * @mixin \Illuminate\Database\Eloquent\Model
28:  */
29: final class ProductCollection extends Model
30: {
31:     use HasFactory;
32: 
33:     protected $table = 'cat_product_collections';
34: 
35:     /**
36:      * The attributes that are mass assignable.
37:      *
38:      * @var list<string>
39:      */
40:     protected $fillable = [
41:         'product_id',
42:         'collection_id',
43:     ];
44: 
45:     /**
46:      * Get the product that owns this collection relationship.
47:      */
48:     public function product(): BelongsTo
49:     {
50:         return $this->belongsTo(Product::class, 'product_id');
51:     }
52: 
53:     /**
54:      * Get the collection that owns this product relationship.
55:      */
56:     public function collection(): BelongsTo
57:     {
58:         return $this->belongsTo(Collection::class, 'collection_id');
59:     }
60: }
```

## Modules/Catalog/app/Models/Product/ProductColor.php

SHA-256 `f646e66d1b04e5fae758a4d56ce2742e391992ca4282f7ed72de7f212be18852`; 45 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Models\Product;
6: 
7: use Illuminate\Database\Eloquent\Model;
8: use Illuminate\Database\Eloquent\Relations\BelongsTo;
9: use Spatie\MediaLibrary\HasMedia;
10: use Spatie\MediaLibrary\InteractsWithMedia;
11: 
12: final class ProductColor extends Model implements HasMedia
13: {
14:     use InteractsWithMedia;
15: 
16:     /**
17:      * The path to the media files.
18:      */
19:     public string $mediaPath = 'cat/products/colors';
20: 
21:     /**
22:      * The table associated with the model.
23:      */
24:     protected $table = 'cat_product_colors';
25: 
26:     /**
27:      * Indicates if the model should be timestamped.
28:      */
29:     public $timestamps = false;
30: 
31:     /**
32:      * The attributes that are mass assignable.
33:      *
34:      * @var list<string>
35:      */
36:     protected $fillable = [
37:         'product_id',
38:         'code',
39:     ];
40: 
41:     public function product(): BelongsTo
42:     {
43:         return $this->belongsTo(Product::class);
44:     }
45: }
```

## Modules/Catalog/app/Models/Product/ProductOffer.php

SHA-256 `f4b02e664bda62d6dac40da41c158e6afbe0d0887feaa0d43c1e833b064f2f2e`; 136 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Models\Product;
6: 
7: use Buyer\Models\Order\Item;
8: use Catalog\Models\PropertyValue;
9: use Illuminate\Database\Eloquent\Factories\HasFactory;
10: use Illuminate\Database\Eloquent\Model;
11: use Illuminate\Database\Eloquent\Relations\BelongsTo;
12: use Illuminate\Database\Eloquent\Relations\BelongsToMany;
13: use Illuminate\Database\Eloquent\Relations\HasMany;
14: use Illuminate\Database\Eloquent\SoftDeletes;
15: 
16: /**
17:  * @property int $id
18:  * @property int $product_id
19:  * @property float $price
20:  * @property int $inventory_quantity
21:  * @property bool $is_active
22:  * @property \Carbon\CarbonImmutable|null $created_at
23:  * @property \Carbon\CarbonImmutable|null $updated_at
24:  * @property \Carbon\CarbonImmutable|null $deleted_at
25:  * @property-read Product $product
26:  * @property-read \Illuminate\Database\Eloquent\Collection<int, PropertyValue> $propertyValues
27:  * @property-read \Illuminate\Database\Eloquent\Collection<int, Item> $orderItems
28:  *
29:  * @method static \Seller\Database\Factories\Shop\ProductOfferFactory factory($count = null, $state = [])
30:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductOffer newModelQuery()
31:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductOffer newQuery()
32:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductOffer query()
33:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductOffer whereCreatedAt($value)
34:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductOffer whereDeletedAt($value)
35:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductOffer whereId($value)
36:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductOffer whereInventoryQuantity($value)
37:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductOffer whereIsActive($value)
38:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductOffer wherePrice($value)
39:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductOffer whereProductId($value)
40:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductOffer whereUpdatedAt($value)
41:  *
42:  * @mixin \Illuminate\Database\Eloquent\Model
43:  */
44: final class ProductOffer extends Model
45: {
46:     use HasFactory, SoftDeletes;
47: 
48:     protected $table = 'cat_product_offers';
49: 
50:     /**
51:      * The attributes that are mass assignable.
52:      *
53:      * @var list<string>
54:      */
55:     protected $fillable = [
56:         'product_id',
57:         'price',
58:         'inventory_quantity',
59:         'is_active',
60:     ];
61: 
62:     /**
63:      * Get the product that owns this offer.
64:      */
65:     public function product(): BelongsTo
66:     {
67:         return $this->belongsTo(Product::class, 'product_id');
68:     }
69: 
70:     /**
71:      * Get the order items for this product offer.
72:      */
73:     public function orderItems(): HasMany
74:     {
75:         return $this->hasMany(Item::class, 'offer_id');
76:     }
77: 
78:     /**
79:      * Values selected for this offer.
80:      */
81:     public function propertyValues(): BelongsToMany
82:     {
83:         return $this->belongsToMany(PropertyValue::class, 'cat_product_offer_property_values', 'product_offer_id', 'property_value_id');
84:     }
85: 
86:     /**
87:      * Scope a query to only include active offers.
88:      */
89:     public function scopeActive($query)
90:     {
91:         return $query->where('is_active', true);
92:     }
93: 
94:     /**
95:      * Delete the model.
96:      *
97:      * Always perform force delete for this model.
98:      * Soft delete is only performed when there are related order items (via deleting event).
99:      */
100:     public function delete(): bool
101:     {
102:         return $this->forceDelete();
103:     }
104: 
105:     /**
106:      * Boot the model.
107:      */
108:     protected static function boot(): void
109:     {
110:         parent::boot();
111: 
112:         self::deleting(function (ProductOffer $productOffer) {
113:             // If there are related order items, perform soft delete
114:             if ($productOffer->orderItems()->exists()) {
115:                 $productOffer->performSoftDelete();
116: 
117:                 return false; // Prevent force delete
118:             }
119:             // If no related order items, continue with force delete
120:         });
121:     }
122: 
123:     /**
124:      * The attributes that should be cast.
125:      *
126:      * @return array<string, string>
127:      */
128:     protected function casts(): array
129:     {
130:         return [
131:             'price' => 'decimal:2',
132:             'inventory_quantity' => 'integer',
133:             'is_active' => 'boolean',
134:         ];
135:     }
136: }
```

## Modules/Catalog/app/Models/Product/ProductOfferPropertyValue.php

SHA-256 `6ff52227b60528fa1c62d957d1a13054256d9499eff706cc3540ed17c0a1fd94`; 44 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Models\Product;
6: 
7: use Catalog\Models\PropertyValue;
8: use Illuminate\Database\Eloquent\Factories\HasFactory;
9: use Illuminate\Database\Eloquent\Model;
10: use Illuminate\Database\Eloquent\Relations\BelongsTo;
11: 
12: /**
13:  * @property int $id
14:  * @property int $product_offer_id
15:  * @property int $property_value_id
16:  * @property-read ProductOffer $offer
17:  * @property-read PropertyValue $propertyValue
18:  */
19: final class ProductOfferPropertyValue extends Model
20: {
21:     use HasFactory;
22: 
23:     public $timestamps = false;
24: 
25:     protected $table = 'cat_product_offer_property_values';
26: 
27:     /**
28:      * @var list<string>
29:      */
30:     protected $fillable = [
31:         'product_offer_id',
32:         'property_value_id',
33:     ];
34: 
35:     public function offer(): BelongsTo
36:     {
37:         return $this->belongsTo(ProductOffer::class, 'product_offer_id');
38:     }
39: 
40:     public function propertyValue(): BelongsTo
41:     {
42:         return $this->belongsTo(PropertyValue::class, 'property_value_id');
43:     }
44: }
```

## Modules/Catalog/app/Models/Product/ProductProperty.php

SHA-256 `3ce9a282acd2521eb2e7607d0669290f124d43ed8e9fd2864e103d38593c1aca`; 59 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Models\Product;
6: 
7: use Catalog\Models\Property;
8: use Illuminate\Database\Eloquent\Factories\HasFactory;
9: use Illuminate\Database\Eloquent\Model;
10: use Illuminate\Database\Eloquent\Relations\BelongsTo;
11: use Illuminate\Database\Eloquent\Relations\BelongsToMany;
12: 
13: /**
14:  * @property int $id
15:  * @property int $product_id
16:  * @property int $property_id
17:  * @property array|null $available_values
18:  * @property-read Product $product
19:  * @property-read Property $property
20:  */
21: final class ProductProperty extends Model
22: {
23:     use HasFactory;
24: 
25:     public $timestamps = false;
26: 
27:     protected $table = 'cat_product_properties';
28: 
29:     /**
30:      * @var list<string>
31:      */
32:     protected $fillable = [
33:         'product_id',
34:         'property_id',
35:         'available_codes',
36:     ];
37: 
38:     public function product(): BelongsTo
39:     {
40:         return $this->belongsTo(Product::class, 'product_id');
41:     }
42: 
43:     public function property(): BelongsTo
44:     {
45:         return $this->belongsTo(Property::class, 'property_id');
46:     }
47: 
48:     public function products(): BelongsToMany
49:     {
50:         return $this->belongsToMany(Product::class, $this->table);
51:     }
52: 
53:     protected function casts(): array
54:     {
55:         return [
56:             'available_codes' => 'array',
57:         ];
58:     }
59: }
```

## Modules/Catalog/app/Models/Product/ProductPropertyPivot.php

SHA-256 `549c9ffed86c1f3e884773d5da4647a2dfb07b5f6a0d0fc4a7d3c7a9c53706f9`; 37 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Models\Product;
6: 
7: use App\Eloquent\Attributes\ArrayAttribute;
8: use Illuminate\Database\Eloquent\Casts\Attribute;
9: use Illuminate\Database\Eloquent\Relations\Pivot;
10: 
11: final class ProductPropertyPivot extends Pivot
12: {
13:     /**
14:      * The table associated with the model.
15:      */
16:     protected $table = 'cat_product_properties';
17: 
18:     /**
19:      * The attributes that should be cast.
20:      *
21:      * @return array<string, string>
22:      */
23:     protected function casts(): array
24:     {
25:         return [
26:             'available_codes' => 'array',
27:         ];
28:     }
29: 
30:     /**
31:      * Get the available codes attribute, ensuring it's always an array.
32:      */
33:     protected function availableCodes(): Attribute
34:     {
35:         return ArrayAttribute::make();
36:     }
37: }
```

## Modules/Catalog/app/Models/Product/ProductSpecificationGroup.php

SHA-256 `7b62f711ba7071a2751ba9a523eda6f3e6f19c622cc869ef11d7d47643782b82`; 100 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Models\Product;
6: 
7: use Illuminate\Database\Eloquent\Factories\HasFactory;
8: use Illuminate\Database\Eloquent\Model;
9: use Illuminate\Database\Eloquent\Relations\BelongsTo;
10: use Illuminate\Database\Eloquent\Relations\HasMany;
11: 
12: /**
13:  * @property int $id
14:  * @property int $product_id
15:  * @property int $specification_id
16:  * @property string|null $name
17:  * @property string $value
18:  * @property int $sort_order
19:  * @property \Carbon\CarbonImmutable|null $created_at
20:  * @property \Carbon\CarbonImmutable|null $updated_at
21:  * @property-read \Seller\Models\Shop\Product $product
22:  * @property-read \Seller\Models\Shop\ProductSpecification $specification
23:  *
24:  * @method static \Seller\Database\Factories\Shop\ProductSpecificationFactory factory($count = null, $state = [])
25:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecification newModelQuery()
26:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecification newQuery()
27:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecification query()
28:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecification whereCreatedAt($value)
29:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecification whereId($value)
30:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecification whereName($value)
31:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecification whereProductId($value)
32:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecification whereSortOrder($value)
33:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecification whereSpecificationId($value)
34:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecification whereUpdatedAt($value)
35:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecification whereValue($value)
36:  *
37:  * @mixin \Illuminate\Database\Eloquent\Model
38:  */
39: final class ProductSpecificationGroup extends Model
40: {
41:     use HasFactory;
42: 
43:     public $timestamps = false;
44: 
45:     protected $table = 'cat_product_specification_groups';
46: 
47:     /**
48:      * The attributes that are mass assignable.
49:      *
50:      * @var list<string>
51:      */
52:     protected $fillable = [
53:         'product_id',
54:         'group_id',
55:         'content',
56:         'sort_order',
57:     ];
58: 
59:     /**
60:      * Get the product that owns this specification.
61:      */
62:     public function product(): BelongsTo
63:     {
64:         return $this->belongsTo(Product::class, 'product_id');
65:     }
66: 
67:     /**
68:      * Get the specification list that owns this specification.
69:      */
70:     public function group(): BelongsTo
71:     {
72:         return $this->belongsTo(SpecificationGroup::class, 'group_id');
73:     }
74: 
75:     public function options(): HasMany
76:     {
77:         return $this->hasMany(ProductSpecificationOption::class, 'group_id');
78:     }
79: 
80:     /**
81:      * Scope a query to order by sort order.
82:      */
83:     public function scopeOrdered($query)
84:     {
85:         return $query->orderBy('sort_order');
86:     }
87: 
88:     /**
89:      * The attributes that should be cast.
90:      *
91:      * @return array<string, string>
92:      */
93:     protected function casts(): array
94:     {
95:         return [
96:             'content' => 'array',
97:             'sort_order' => 'integer',
98:         ];
99:     }
100: }
```

## Modules/Catalog/app/Models/Product/ProductSpecificationOption.php

SHA-256 `d2c68a3292002031b7185c5c3ca4eaf47d4d5828d9872fb20628965bd5d255a4`; 94 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Models\Product;
6: 
7: use Illuminate\Database\Eloquent\Factories\HasFactory;
8: use Illuminate\Database\Eloquent\Model;
9: use Illuminate\Database\Eloquent\Relations\BelongsTo;
10: 
11: /**
12:  * @property int $id
13:  * @property int $product_id
14:  * @property int $group_id
15:  * @property string|null $name
16:  * @property string $value
17:  * @property int $sort_order
18:  * @property \Carbon\CarbonImmutable|null $created_at
19:  * @property \Carbon\CarbonImmutable|null $updated_at
20:  * @property-read \Seller\Models\Shop\Product $product
21:  * @property-read \Seller\Models\Shop\ProductSpecificationGroup $group
22:  *
23:  * @method static \Seller\Database\Factories\Shop\ProductSpecificationFactory factory($count = null, $state = [])
24:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecification newModelQuery()
25:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecification newQuery()
26:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecification query()
27:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecification whereCreatedAt($value)
28:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecification whereId($value)
29:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecification whereName($value)
30:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecification whereProductId($value)
31:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecification whereSortOrder($value)
32:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecification whereGroupId($value)
33:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecification whereUpdatedAt($value)
34:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecification whereValue($value)
35:  *
36:  * @mixin \Illuminate\Database\Eloquent\Model
37:  */
38: final class ProductSpecificationOption extends Model
39: {
40:     use HasFactory;
41: 
42:     public $timestamps = false;
43: 
44:     protected $table = 'cat_product_specification_options';
45: 
46:     /**
47:      * The attributes that are mass assignable.
48:      *
49:      * @var list<string>
50:      */
51:     protected $fillable = [
52:         'product_id',
53:         'group_id',
54:         'content',
55:         'sort_order',
56:     ];
57: 
58:     /**
59:      * Get the product that owns this specification.
60:      */
61:     public function product(): BelongsTo
62:     {
63:         return $this->belongsTo(Product::class, 'product_id');
64:     }
65: 
66:     /**
67:      * Get the specification list that owns this specification.
68:      */
69:     public function group(): BelongsTo
70:     {
71:         return $this->belongsTo(ProductSpecificationGroup::class, 'group_id');
72:     }
73: 
74:     /**
75:      * Scope a query to order by sort order.
76:      */
77:     public function scopeOrdered($query)
78:     {
79:         return $query->orderBy('sort_order');
80:     }
81: 
82:     /**
83:      * The attributes that should be cast.
84:      *
85:      * @return array<string, string>
86:      */
87:     protected function casts(): array
88:     {
89:         return [
90:             'content' => 'array',
91:             'sort_order' => 'integer',
92:         ];
93:     }
94: }
```

## Modules/Catalog/app/Models/Product/ProductTag.php

SHA-256 `8703900a696a2122968a063156cd714484339d9f8b5dcc1f7ee99d7b6fb00b2b`; 34 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Models\Product;
6: 
7: use Catalog\Models\Tag;
8: use Illuminate\Database\Eloquent\Model;
9: use Illuminate\Database\Eloquent\Relations\BelongsTo;
10: 
11: final class ProductTag extends Model
12: {
13:     protected $table = 'cat_product_tags';
14: 
15:     /**
16:      * The attributes that are mass assignable.
17:      *
18:      * @var list<string>
19:      */
20:     protected $fillable = [
21:         'product_id',
22:         'tag_id',
23:     ];
24: 
25:     public function product(): BelongsTo
26:     {
27:         return $this->belongsTo(Product::class);
28:     }
29: 
30:     public function tag(): BelongsTo
31:     {
32:         return $this->belongsTo(Tag::class);
33:     }
34: }
```

## Modules/Catalog/app/Models/Product/SpecificationGroup.php

SHA-256 `6fce4d2f8f5a817c3f7f982c1e4152dd64c718c6b853b7665261824645d876c7`; 82 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Models\Product;
6: 
7: use App\Models\User;
8: use Illuminate\Database\Eloquent\Factories\HasFactory;
9: use Illuminate\Database\Eloquent\Model;
10: use Illuminate\Database\Eloquent\Relations\BelongsTo;
11: use Illuminate\Database\Eloquent\Relations\HasMany;
12: use Illuminate\Database\Eloquent\SoftDeletes;
13: 
14: /**
15:  * @property int $id
16:  * @property string $name
17:  * @property int|null $created_by_id
18:  * @property \Carbon\CarbonImmutable|null $created_at
19:  * @property \Carbon\CarbonImmutable|null $updated_at
20:  * @property \Carbon\CarbonImmutable|null $deleted_at
21:  * @property-read User|null $createdBy
22:  * @property-read \Illuminate\Database\Eloquent\Collection<int, ProductSpecification> $specifications
23:  * @property-read int|null $specifications_count
24:  *
25:  * @method static \Seller\Database\Factories\Shop\ProductSpecificationListFactory factory($count = null, $state = [])
26:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecificationList newModelQuery()
27:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecificationList newQuery()
28:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecificationList query()
29:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecificationList whereCreatedAt($value)
30:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecificationList whereCreatedById($value)
31:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecificationList whereDeletedAt($value)
32:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecificationList whereId($value)
33:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecificationList whereName($value)
34:  * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSpecificationList whereUpdatedAt($value)
35:  *
36:  * @mixin \Illuminate\Database\Eloquent\Model
37:  */
38: final class SpecificationGroup extends Model
39: {
40:     use HasFactory, SoftDeletes;
41: 
42:     protected $table = 'cat_specification_groups';
43: 
44:     /**
45:      * The attributes that are mass assignable.
46:      *
47:      * @var list<string>
48:      */
49:     protected $fillable = [
50:         'name',
51:         'created_by_id',
52:         'sort_order',
53:     ];
54: 
55:     /**
56:      * Get the user who created this specification list.
57:      */
58:     public function createdBy(): BelongsTo
59:     {
60:         return $this->belongsTo(User::class, 'created_by_id');
61:     }
62: 
63:     /**
64:      * Get product specification group blocks that belong to this group.
65:      */
66:     public function productSpecificationGroups(): HasMany
67:     {
68:         return $this->hasMany(ProductSpecificationGroup::class, 'group_id');
69:     }
70: 
71:     /**
72:      * The attributes that should be cast.
73:      *
74:      * @return array<string, string>
75:      */
76:     protected function casts(): array
77:     {
78:         return [
79:             'sort_order' => 'integer',
80:         ];
81:     }
82: }
```

## Modules/Catalog/app/Models/Property.php

SHA-256 `31526d4a6a261f7262cbddd375af70e2dfd05f22fe536b002d9a6ced15d230ee`; 82 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Models;
6: 
7: use Catalog\Enums\Product\PropertyControl;
8: use Catalog\Enums\Product\PropertyType;
9: use Catalog\Enums\Product\PropertyUnit;
10: use Catalog\Models\Product\Product;
11: use Catalog\Models\Product\ProductProperty;
12: use Illuminate\Database\Eloquent\Factories\HasFactory;
13: use Illuminate\Database\Eloquent\Model;
14: use Illuminate\Database\Eloquent\Relations\BelongsTo;
15: use Illuminate\Database\Eloquent\Relations\BelongsToMany;
16: use Illuminate\Database\Eloquent\Relations\HasMany;
17: use Sales\Models\Shop\Shop;
18: 
19: /**
20:  * @property int $id
21:  * @property int|null $shop_id
22:  * @property PropertyType $type
23:  * @property PropertyControl|null $control
24:  * @property PropertyUnit|null $unit
25:  * @property int $sort_order
26:  * @property-read Shop|null $shop
27:  * @property-read \Illuminate\Database\Eloquent\Collection<int, PropertyValue> $values
28:  * @property-read \Illuminate\Database\Eloquent\Collection<int, Product> $products
29:  */
30: final class Property extends Model
31: {
32:     use HasFactory;
33: 
34:     public $timestamps = false;
35: 
36:     protected $table = 'cat_properties';
37: 
38:     /**
39:      * @var list<string>
40:      */
41:     protected $fillable = [
42:         'shop_id',
43:         'code',
44:         'type',
45:         'control',
46:         'name',
47:         'unit',
48:         'sort_order',
49:     ];
50: 
51:     public function shop(): BelongsTo
52:     {
53:         return $this->belongsTo(Shop::class);
54:     }
55: 
56:     public function products(): BelongsToMany
57:     {
58:         return $this->belongsToMany(Product::class, ProductProperty::class)
59:             ->using(\Catalog\Models\Product\ProductPropertyPivot::class)
60:             ->withPivot('available_codes');
61:     }
62: 
63:     public function values(): HasMany
64:     {
65:         return $this->hasMany(PropertyValue::class, 'property_id');
66:     }
67: 
68:     public function scopeOrdered($query)
69:     {
70:         return $query->orderBy('sort_order');
71:     }
72: 
73:     protected function casts(): array
74:     {
75:         return [
76:             'type' => PropertyType::class,
77:             'control' => PropertyControl::class,
78:             'unit' => PropertyUnit::class,
79:             'sort_order' => 'integer',
80:         ];
81:     }
82: }
```

## Modules/Catalog/app/Models/PropertyValue.php

SHA-256 `c326f5bf6eae997317625ca01da84ee051a76ec58ab57b89a95a9658b1b68c79`; 51 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Models;
6: 
7: use Illuminate\Database\Eloquent\Factories\HasFactory;
8: use Illuminate\Database\Eloquent\Model;
9: use Illuminate\Database\Eloquent\Relations\BelongsTo;
10: 
11: /**
12:  * @property int $id
13:  * @property int $property_id
14:  * @property string|null $value_text
15:  * @property int|null $value_integer
16:  * @property float|null $value_decimal
17:  * @property-read Property $property
18:  */
19: final class PropertyValue extends Model
20: {
21:     use HasFactory;
22: 
23:     public $timestamps = false;
24: 
25:     protected $table = 'cat_property_values';
26: 
27:     /**
28:      * @var list<string>
29:      */
30:     protected $fillable = [
31:         'property_id',
32:         'value_text',
33:         'value_integer',
34:         'value_decimal',
35:         'code',
36:         'label',
37:     ];
38: 
39:     public function property(): BelongsTo
40:     {
41:         return $this->belongsTo(Property::class, 'property_id');
42:     }
43: 
44:     protected function casts(): array
45:     {
46:         return [
47:             'value_integer' => 'integer',
48:             'value_decimal' => 'decimal:4',
49:         ];
50:     }
51: }
```

## Modules/Catalog/app/Models/Tag.php

SHA-256 `4e24e8e0d064d034f2fcdc760f2223d7a2162b5244f4da282b0a9a0ddca24da1`; 22 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Models;
6: 
7: use Illuminate\Database\Eloquent\Model;
8: 
9: final class Tag extends Model
10: {
11:     protected $table = 'cat_tags';
12: 
13:     /**
14:      * The attributes that are mass assignable.
15:      *
16:      * @var list<string>
17:      */
18:     protected $fillable = [
19:         'name',
20:         'slug',
21:     ];
22: }
```

## Modules/Sales/app/Enums/Company/LegalStatus.php

SHA-256 `ae786367a5226a9abc1a82c107bfb8111af9db19a460d1a13e233613553f1808`; 29 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Enums\Company;
6: 
7: use Filament\Support\Contracts\HasLabel;
8: 
9: enum LegalStatus: string implements HasLabel
10: {
11:     case LegalEntity = 'legal_entity';
12: 
13:     case Individual = 'individual';
14: 
15:     case SelfEmployed = 'self_employed';
16: 
17:     case IndividualEntrepreneur = 'individual_entrepreneur';
18: 
19:     public function getLabel(): string
20:     {
21:         return match ($this) {
22:             self::LegalEntity => 'Legal Entity (ООО / AO, etc.)',
23:             self::Individual => 'Individual (Физическое лицо)',
24:             self::SelfEmployed => 'Self-Employed (Самозанятый)',
25:             self::IndividualEntrepreneur => 'Individual Entrepreneur (ИП)',
26:         };
27:     }
28: }
29: 
```

## Modules/Sales/app/Enums/SalesPlatform.php

SHA-256 `cc0bb3de11b3ce22ff3a2c124edeec3a47c9108dd652b6713d74708e635528de`; 26 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Enums;
6: 
7: use Filament\Support\Contracts\HasLabel;
8: 
9: enum SalesPlatform: string implements HasLabel
10: {
11:     case Ozon = 'ozon';
12: 
13:     case Wildberries = 'wildberries';
14: 
15:     case YandexMarket = 'yandex_market';
16: 
17:     public function getLabel(): string
18:     {
19:         return match ($this) {
20:             self::Ozon => 'Ozon',
21:             self::Wildberries => 'Wildberries',
22:             self::YandexMarket => 'Yandex Market',
23:         };
24:     }
25: }
26: 
```

## Modules/Sales/app/Enums/Shop/InventoryPolicy.php

SHA-256 `15686a3b8a3fc6fddb314cf13ca96f960ded9b7d51bd07a1a567db432ef800c4`; 23 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Enums\Shop;
6: 
7: use Filament\Support\Contracts\HasLabel;
8: 
9: enum InventoryPolicy: string implements HasLabel
10: {
11:     case InStock = 'in_stock';
12: 
13:     case OutOfStock = 'out_of_stock';
14: 
15:     public function getLabel(): string
16:     {
17:         return match ($this) {
18:             self::InStock => 'В наличии',
19:             self::OutOfStock => 'Нет в наличии',
20:         };
21:     }
22: }
23: 
```

## Modules/Sales/app/Enums/TeamPermission.php

SHA-256 `e6781a7e0a039ba27cf974d80b454319e0fa8aedce98c0f74e6a58c6118b0bde`; 26 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Enums;
6: 
7: use Filament\Support\Contracts\HasLabel;
8: 
9: enum TeamPermission: string implements HasLabel
10: {
11:     case Orders = 'orders';
12: 
13:     case Products = 'products';
14: 
15:     case Admin = 'admin';
16: 
17:     public function getLabel(): string
18:     {
19:         return match ($this) {
20:             self::Orders => 'Заказы и Возвраты',
21:             self::Products => 'Товары',
22:             self::Admin => 'Команда',
23:         };
24:     }
25: }
26: 
```

## Modules/Sales/app/Models/Company/BusinessType.php

SHA-256 `1051ca5af5cf49de872adb2ee3e168ea0a32b057ffc3b1964564d385afb94e93`; 43 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Models\Company;
6: 
7: use Illuminate\Database\Eloquent\Model;
8: use Illuminate\Database\Eloquent\SoftDeletes;
9: 
10: /**
11:  * @property int $id
12:  * @property string|null $name
13:  * @property \Carbon\CarbonImmutable|null $created_at
14:  * @property \Carbon\CarbonImmutable|null $updated_at
15:  * @property \Carbon\CarbonImmutable|null $deleted_at
16:  *
17:  * @method static \Database\Factories\Company\BusinessTypeFactory factory($count = null, $state = [])
18:  * @method static \Illuminate\Database\Eloquent\Builder<static>|BusinessType newModelQuery()
19:  * @method static \Illuminate\Database\Eloquent\Builder<static>|BusinessType newQuery()
20:  * @method static \Illuminate\Database\Eloquent\Builder<static>|BusinessType query()
21:  * @method static \Illuminate\Database\Eloquent\Builder<static>|BusinessType whereCreatedAt($value)
22:  * @method static \Illuminate\Database\Eloquent\Builder<static>|BusinessType whereDeletedAt($value)
23:  * @method static \Illuminate\Database\Eloquent\Builder<static>|BusinessType whereId($value)
24:  * @method static \Illuminate\Database\Eloquent\Builder<static>|BusinessType whereName($value)
25:  * @method static \Illuminate\Database\Eloquent\Builder<static>|BusinessType whereUpdatedAt($value)
26:  *
27:  * @mixin \Illuminate\Database\Eloquent\Model
28:  */
29: final class BusinessType extends Model
30: {
31:     use SoftDeletes;
32: 
33:     protected $table = 'sales_company_business_types';
34: 
35:     /**
36:      * The attributes that are mass assignable.
37:      *
38:      * @var list<string>
39:      */
40:     protected $fillable = [
41:         'name',
42:     ];
43: }
```

## Modules/Sales/app/Models/Company/Company.php

SHA-256 `406b39d94948ae38d4476fc6141a853bdbaa6c4fd49e2544a0dbef8a20fac9b8`; 179 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Models\Company;
6: 
7: use App\Models\Geo\Address;
8: use App\Models\User;
9: use Illuminate\Database\Eloquent\Model;
10: use Illuminate\Database\Eloquent\Relations\BelongsTo;
11: use Illuminate\Database\Eloquent\Relations\MorphMany;
12: use Illuminate\Database\Eloquent\Relations\MorphOne;
13: use Sales\Enums\Company\LegalStatus;
14: use Sales\Models\Seller\Seller;
15: use Spatie\MediaLibrary\HasMedia;
16: use Spatie\MediaLibrary\InteractsWithMedia;
17: use Spatie\Sluggable\HasSlug;
18: use Spatie\Sluggable\SlugOptions;
19: 
20: /**
21:  * @property int $id
22:  * @property string $slug
23:  * @property string|null $name
24:  * @property LegalStatus $legal_status
25:  * @property string|null $legal_name
26:  * @property string|null $email
27:  * @property string|null $phone_number
28:  * @property string|null $inn
29:  * @property string|null $kpp
30:  * @property string|null $ogrn
31:  * @property string|null $okpo
32:  * @property string|null $description
33:  * @property bool $logo_verified
34:  * @property int|null $verified_by_id
35:  * @property \Carbon\CarbonImmutable|null $verified_at
36:  * @property \Carbon\CarbonImmutable|null $created_at
37:  * @property \Carbon\CarbonImmutable|null $updated_at
38:  * @property-read Address|null $address
39:  * @property-read User|null $verifiedBy
40:  *
41:  * @method static \Database\Factories\Seller\CompanyFactory factory($count = null, $state = [])
42:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Company newModelQuery()
43:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Company newQuery()
44:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Company query()
45:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereCreatedAt($value)
46:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereDescription($value)
47:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereEmail($value)
48:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereId($value)
49:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereInn($value)
50:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereKpp($value)
51:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereLegalStatus($value)
52:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereLegalName($value)
53:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereLogoVerified($value)
54:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereName($value)
55:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereOgrn($value)
56:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereOkpo($value)
57:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Company wherePhoneNumber($value)
58:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereSlug($value)
59:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereUpdatedAt($value)
60:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereVerifiedAt($value)
61:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereVerifiedById($value)
62:  *
63:  * @mixin \Illuminate\Database\Eloquent\Model
64:  */
65: final class Company extends Model implements HasMedia
66: {
67:     use HasSlug, InteractsWithMedia;
68: 
69:     public const MEDIA_LOGO_COLLECTION = 'logo';
70: 
71:     /**
72:      * The path to the media files.
73:      */
74:     public string $mediaPath = 'seller/companies';
75: 
76:     /**
77:      * The table associated with the model.
78:      *
79:      * @var string
80:      */
81:     protected $table = 'sales_companies';
82: 
83:     /**
84:      * The attributes that are mass assignable.
85:      *
86:      * @var list<string>
87:      */
88:     protected $fillable = [
89:         'seller_id',
90:         'slug',
91:         'name',
92:         'legal_status',
93:         'legal_name',
94:         'email',
95:         'phone_number',
96:         'inn',
97:         'kpp',
98:         'ogrn',
99:         'okpo',
100:         'description',
101:         'logo_verified',
102:         'verified_by_id',
103:         'verified_at',
104:     ];
105: 
106:     /**
107:      * Get the options for generating the slug.
108:      */
109:     public function getSlugOptions(): SlugOptions
110:     {
111:         return SlugOptions::create()
112:             ->generateSlugsFrom('name')
113:             ->saveSlugsTo('slug');
114:     }
115: 
116:     /**
117:      * Get the user who verified this company.
118:      */
119:     public function verifiedBy(): BelongsTo
120:     {
121:         return $this->belongsTo(User::class, 'verified_by_id');
122:     }
123: 
124:     /**
125:      * Get the seller that owns this company.
126:      */
127:     public function seller(): BelongsTo
128:     {
129:         return $this->belongsTo(Seller::class);
130:     }
131: 
132:     /**
133:      * Scope a query to only include verified companies.
134:      */
135:     public function scopeVerified($query)
136:     {
137:         return $query->whereNotNull('verified_at');
138:     }
139: 
140:     /**
141:      * Get the address for this company.
142:      */
143:     public function address(): MorphOne
144:     {
145:         return $this->morphOne(Address::class, 'entity');
146:     }
147: 
148:     /**
149:      * Get the addresses for this company.
150:      */
151:     public function addresses(): MorphMany
152:     {
153:         return $this->morphMany(Address::class, 'entity');
154:     }
155: 
156:     /**
157:      * Register media collections for the company.
158:      */
159:     public function registerMediaCollections(): void
160:     {
161:         $this->addMediaCollection(name: self::MEDIA_LOGO_COLLECTION)
162:             ->acceptsMimeTypes(mimeTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
163:             ->singleFile();
164:     }
165: 
166:     /**
167:      * The attributes that should be cast.
168:      *
169:      * @return array<string, string>
170:      */
171:     protected function casts(): array
172:     {
173:         return [
174:             'legal_status' => LegalStatus::class,
175:             'logo_verified' => 'boolean',
176:             'verified_at' => 'date',
177:         ];
178:     }
179: }
```

## Modules/Sales/app/Models/Seller/Contact.php

SHA-256 `97c13b77954ddbb24948b508a2fe36d21c017cc948fb78fc01e9f0ca9e12118f`; 117 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Models\Seller;
6: 
7: use App\Models\User;
8: use Illuminate\Database\Eloquent\Model;
9: use Illuminate\Database\Eloquent\Relations\BelongsTo;
10: 
11: /**
12:  * @property int $id
13:  * @property int $seller_id
14:  * @property string|null $first_name
15:  * @property string|null $middle_name
16:  * @property string|null $last_name
17:  * @property string|null $email
18:  * @property string|null $phone_number
19:  * @property \Carbon\CarbonImmutable|null $verified_at
20:  * @property int|null $verified_by_id
21:  * @property \Carbon\CarbonImmutable|null $created_at
22:  * @property \Carbon\CarbonImmutable|null $updated_at
23:  * @property-read Seller $seller
24:  * @property-read User|null $verifiedBy
25:  *
26:  * @method static \Database\Factories\Seller\ContactFactory factory($count = null, $state = [])
27:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact newModelQuery()
28:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact newQuery()
29:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact query()
30:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereCreatedAt($value)
31:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereEmail($value)
32:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereFirstName($value)
33:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereId($value)
34:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereLastName($value)
35:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereMiddleName($value)
36:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact wherePhoneNumber($value)
37:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereSellerId($value)
38:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereUpdatedAt($value)
39:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereVerifiedAt($value)
40:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereVerifiedById($value)
41:  *
42:  * @mixin \Illuminate\Database\Eloquent\Model
43:  */
44: final class Contact extends Model
45: {
46:     protected $table = 'sales_seller_contacts';
47: 
48:     /**
49:      * The attributes that are mass assignable.
50:      *
51:      * @var list<string>
52:      */
53:     protected $fillable = [
54:         'seller_id',
55:         'first_name',
56:         'middle_name',
57:         'last_name',
58:         'email',
59:         'phone_number',
60:         'verified_at',
61:         'verified_by_id',
62:     ];
63: 
64:     /**
65:      * Get the seller that owns this contact.
66:      */
67:     public function seller(): BelongsTo
68:     {
69:         return $this->belongsTo(Seller::class);
70:     }
71: 
72:     /**
73:      * Get the user who verified this contact.
74:      */
75:     public function verifiedBy(): BelongsTo
76:     {
77:         return $this->belongsTo(User::class, 'verified_by_id');
78:     }
79: 
80:     /**
81:      * Get the contact's full name.
82:      */
83:     public function getFullNameAttribute(): string
84:     {
85:         $name = $this->first_name ?? '';
86: 
87:         if ($this->middle_name) {
88:             $name .= ' ' . $this->middle_name;
89:         }
90: 
91:         if ($this->last_name) {
92:             $name .= ' ' . $this->last_name;
93:         }
94: 
95:         return mb_trim($name);
96:     }
97: 
98:     /**
99:      * Scope a query to only include verified contacts.
100:      */
101:     public function scopeVerified($query)
102:     {
103:         return $query->whereNotNull('verified_at');
104:     }
105: 
106:     /**
107:      * The attributes that should be cast.
108:      *
109:      * @return array<string, string>
110:      */
111:     protected function casts(): array
112:     {
113:         return [
114:             'verified_at' => 'date',
115:         ];
116:     }
117: }
```

## Modules/Sales/app/Models/Seller/SalesModeration.php

SHA-256 `5bf997343f679ce1858395a740f4bcb004f80559e1a7ff2053918e05e6a05527`; 50 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Models\Seller;
6: 
7: use App\Enums\Common\Moderation\ModerationStatus;
8: use App\Models\User;
9: use Illuminate\Database\Eloquent\Model;
10: use Illuminate\Database\Eloquent\Relations\BelongsTo;
11: 
12: final class SalesModeration extends Model
13: {
14:     protected $table = 'sales_moderations';
15: 
16:     protected $fillable = [
17:         'seller_id',
18:         'moderated_by_id',
19:         'moderatable_id',
20:         'moderatable_type',
21:         'status',
22:         'moderation_reason',
23:         'moderation_data',
24:         'moderated_at',
25:     ];
26: 
27:     public function moderatedBy(): BelongsTo
28:     {
29:         return $this->belongsTo(User::class, 'moderated_by_id');
30:     }
31: 
32:     public function seller(): BelongsTo
33:     {
34:         return $this->belongsTo(Seller::class, 'seller_id');
35:     }
36: 
37:     public function moderatable()
38:     {
39:         return $this->morphTo(name: 'moderatable');
40:     }
41: 
42:     protected function casts(): array
43:     {
44:         return [
45:             'status' => ModerationStatus::class,
46:             'moderation_data' => 'array',
47:             'moderated_at' => 'datetime',
48:         ];
49:     }
50: }
```

## Modules/Sales/app/Models/Seller/Seller.php

SHA-256 `33448170d405540a63e0c8135a441a9703b14f299ac9a50809ebf09b807b4548`; 165 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Models\Seller;
6: 
7: use Illuminate\Database\Eloquent\Factories\HasFactory;
8: use Illuminate\Database\Eloquent\Model;
9: use Illuminate\Database\Eloquent\Relations\HasMany;
10: use Illuminate\Database\Eloquent\Relations\HasOne;
11: use Illuminate\Database\Eloquent\SoftDeletes;
12: use Sales\Models\Company\Company;
13: use Sales\Models\Shop\Shop;
14: use Spatie\MediaLibrary\HasMedia;
15: use Spatie\MediaLibrary\InteractsWithMedia;
16: 
17: /**
18:  * @property int $id
19:  * @property string $name
20:  * @property string|null $description
21:  * @property bool $is_active
22:  * @property bool|null $verified
23:  * @property \Carbon\CarbonImmutable|null $created_at
24:  * @property \Carbon\CarbonImmutable|null $updated_at
25:  * @property \Carbon\CarbonImmutable|null $deleted_at
26:  * @property-read \Illuminate\Database\Eloquent\Collection<int, Shop> $shops
27:  * @property-read int|null $shops_count
28:  *
29:  * @method static \Sales\Database\Factories\Seller\SellerFactory factory($count = null, $state = [])
30:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Seller newModelQuery()
31:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Seller newQuery()
32:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Seller query()
33:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Seller whereCreatedAt($value)
34:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Seller whereDeletedAt($value)
35:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Seller whereCreatedAt($value)
36:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Seller whereDescription($value)
37:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Seller whereId($value)
38:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Seller whereIsActive($value)
39:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Seller whereName($value)
40:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Seller whereUpdatedAt($value)
41:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Seller whereVerified($value)
42:  *
43:  * @mixin \Illuminate\Database\Eloquent\Model
44:  */
45: final class Seller extends Model implements HasMedia
46: {
47:     use HasFactory, InteractsWithMedia, SoftDeletes;
48: 
49:     public string $mediaPath = 'sales/sellers';
50: 
51:     protected $table = 'sales_sellers';
52: 
53:     /**
54:      * The attributes that are mass assignable.
55:      *
56:      * @var list<string>
57:      */
58:     protected $fillable = [
59:         'name',
60:         'description',
61:         'is_active',
62:         'verified',
63:     ];
64: 
65:     /**
66:      * Get the shops for this seller.
67:      */
68:     public function shops(): HasMany
69:     {
70:         return $this->hasMany(Shop::class);
71:     }
72: 
73:     /**
74:      * Get the shop for this seller.
75:      */
76:     public function shop(): HasOne
77:     {
78:         return $this->hasOne(Shop::class);
79:     }
80: 
81:     /**
82:      * Get the company for this seller.
83:      */
84:     public function company(): HasOne
85:     {
86:         return $this->hasOne(Company::class, 'seller_id');
87:     }
88: 
89:     /**
90:      * Get the business types for this seller.
91:      */
92:     public function businessTypes(): HasMany
93:     {
94:         return $this->hasMany(SellerBusinessType::class);
95:     }
96: 
97:     /**
98:      * Get the team members for this seller.
99:      */
100:     public function teamMembers(): HasMany
101:     {
102:         return $this->hasMany(Team::class);
103:     }
104: 
105:     /**
106:      * Get the warehouses for this seller.
107:      */
108:     public function warehouses(): HasMany
109:     {
110:         return $this->hasMany(Warehouse::class, 'seller_id');
111:     }
112: 
113:     /**
114:      * Get the settings for this seller.
115:      */
116:     public function settings(): HasOne
117:     {
118:         return $this->hasOne(Settings::class);
119:     }
120: 
121:     /**
122:      * Get the contacts for this seller.
123:      */
124:     public function contacts(): HasMany
125:     {
126:         return $this->hasMany(Contact::class);
127:     }
128: 
129:     /**
130:      * Scope a query to only include active sellers.
131:      */
132:     public function scopeActive($query)
133:     {
134:         return $query->where('is_active', true);
135:     }
136: 
137:     /**
138:      * Scope a query to only include verified sellers.
139:      */
140:     public function scopeVerified($query)
141:     {
142:         return $query->where('verified', true);
143:     }
144: 
145:     /**
146:      * Create a new factory instance for the model.
147:      */
148:     protected static function newFactory()
149:     {
150:         return \Sales\Database\Factories\Seller\SellerFactory::new();
151:     }
152: 
153:     /**
154:      * The attributes that should be cast.
155:      *
156:      * @return array<string, string>
157:      */
158:     protected function casts(): array
159:     {
160:         return [
161:             'is_active' => 'boolean',
162:             'verified' => 'boolean',
163:         ];
164:     }
165: }
```

## Modules/Sales/app/Models/Seller/SellerBusinessType.php

SHA-256 `867e30c4195f1ca9b5976472651e345134787d71e40ec0aefcb6bb04485db558`; 84 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Models\Seller;
6: 
7: use Illuminate\Database\Eloquent\Model;
8: use Illuminate\Database\Eloquent\Relations\BelongsTo;
9: use Sales\Models\Company\BusinessType;
10: 
11: /**
12:  * @property int $id
13:  * @property int $seller_id
14:  * @property int $type_id
15:  * @property \Carbon\CarbonImmutable|null $verified_at
16:  * @property \Carbon\CarbonImmutable|null $created_at
17:  * @property \Carbon\CarbonImmutable|null $updated_at
18:  * @property-read Seller $seller
19:  * @property-read BusinessType $type
20:  *
21:  * @method static \Database\Factories\Seller\SellerBusinessTypeFactory factory($count = null, $state = [])
22:  * @method static \Illuminate\Database\Eloquent\Builder<static>|SellerBusinessType newModelQuery()
23:  * @method static \Illuminate\Database\Eloquent\Builder<static>|SellerBusinessType newQuery()
24:  * @method static \Illuminate\Database\Eloquent\Builder<static>|SellerBusinessType query()
25:  * @method static \Illuminate\Database\Eloquent\Builder<static>|SellerBusinessType whereCreatedAt($value)
26:  * @method static \Illuminate\Database\Eloquent\Builder<static>|SellerBusinessType whereId($value)
27:  * @method static \Illuminate\Database\Eloquent\Builder<static>|SellerBusinessType whereSellerId($value)
28:  * @method static \Illuminate\Database\Eloquent\Builder<static>|SellerBusinessType whereTypeId($value)
29:  * @method static \Illuminate\Database\Eloquent\Builder<static>|SellerBusinessType whereUpdatedAt($value)
30:  * @method static \Illuminate\Database\Eloquent\Builder<static>|SellerBusinessType whereVerifiedAt($value)
31:  *
32:  * @mixin \Illuminate\Database\Eloquent\Model
33:  */
34: final class SellerBusinessType extends Model
35: {
36:     protected $table = 'sales_seller_business_types';
37: 
38:     /**
39:      * The attributes that are mass assignable.
40:      *
41:      * @var list<string>
42:      */
43:     protected $fillable = [
44:         'seller_id',
45:         'type_id',
46:         'verified_at',
47:     ];
48: 
49:     /**
50:      * Get the seller that owns this business type.
51:      */
52:     public function seller(): BelongsTo
53:     {
54:         return $this->belongsTo(Seller::class);
55:     }
56: 
57:     /**
58:      * Get the business type.
59:      */
60:     public function type(): BelongsTo
61:     {
62:         return $this->belongsTo(BusinessType::class, 'type_id');
63:     }
64: 
65:     /**
66:      * Scope a query to only include verified business types.
67:      */
68:     public function scopeVerified($query)
69:     {
70:         return $query->whereNotNull('verified_at');
71:     }
72: 
73:     /**
74:      * The attributes that should be cast.
75:      *
76:      * @return array<string, string>
77:      */
78:     protected function casts(): array
79:     {
80:         return [
81:             'verified_at' => 'date',
82:         ];
83:     }
84: }
```

## Modules/Sales/app/Models/Seller/Settings.php

SHA-256 `723de4cd9b0cfa1441f1a26fd382c9ccecdcc1b836dd141c77f213b6a648bf6e`; 74 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Models\Seller;
6: 
7: use Illuminate\Database\Eloquent\Casts\AsEnumArrayObject;
8: use Illuminate\Database\Eloquent\Model;
9: use Illuminate\Database\Eloquent\Relations\BelongsTo;
10: use Sales\Enums\SalesPlatform;
11: 
12: /**
13:  * @property int $id
14:  * @property int $seller_id
15:  * @property string $number_of_products
16:  * @property string $monthly_sales_estimate
17:  * @property string $average_fulfilment_time
18:  * @property array $other_sales_platforms_used
19:  * @property \Carbon\CarbonImmutable|null $created_at
20:  * @property \Carbon\CarbonImmutable|null $updated_at
21:  * @property-read Seller $seller
22:  *
23:  * @method static \Database\Factories\Seller\SettingsFactory factory($count = null, $state = [])
24:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Settings newModelQuery()
25:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Settings newQuery()
26:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Settings query()
27:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Settings whereAverageFulfilmentTime($value)
28:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Settings whereCreatedAt($value)
29:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Settings whereId($value)
30:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Settings whereMonthlySalesEstimate($value)
31:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Settings whereNumberOfProducts($value)
32:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Settings whereOtherSalesPlatformsUsed($value)
33:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Settings whereSellerId($value)
34:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Settings whereUpdatedAt($value)
35:  *
36:  * @mixin \Illuminate\Database\Eloquent\Model
37:  */
38: final class Settings extends Model
39: {
40:     protected $table = 'sales_seller_settings';
41: 
42:     /**
43:      * The attributes that are mass assignable.
44:      *
45:      * @var list<string>
46:      */
47:     protected $fillable = [
48:         'seller_id',
49:         'number_of_products',
50:         'monthly_sales_estimate',
51:         'average_fulfilment_time',
52:         'other_sales_platforms_used',
53:     ];
54: 
55:     /**
56:      * Get the seller that owns these settings.
57:      */
58:     public function seller(): BelongsTo
59:     {
60:         return $this->belongsTo(Seller::class);
61:     }
62: 
63:     /**
64:      * The attributes that should be cast.
65:      *
66:      * @return array<string, string>
67:      */
68:     protected function casts(): array
69:     {
70:         return [
71:             'other_sales_platforms_used' => AsEnumArrayObject::of(SalesPlatform::class),
72:         ];
73:     }
74: }
```

## Modules/Sales/app/Models/Seller/Team.php

SHA-256 `5cfe5c95dd40699077cfc7e12517d4cd80a9b8f6b45dcc9d1bf3c2c66755d789`; 80 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Models\Seller;
6: 
7: use App\Models\User;
8: use Illuminate\Database\Eloquent\Factories\HasFactory;
9: use Illuminate\Database\Eloquent\Model;
10: use Illuminate\Database\Eloquent\Relations\BelongsTo;
11: use Illuminate\Database\Eloquent\Relations\HasMany;
12: 
13: /**
14:  * @property int $id
15:  * @property int $user_id
16:  * @property int $seller_id
17:  * @property \Carbon\CarbonImmutable|null $created_at
18:  * @property \Carbon\CarbonImmutable|null $updated_at
19:  * @property-read User $user
20:  * @property-read Seller $seller
21:  * @property-read \Illuminate\Database\Eloquent\Collection<int, TeamPermission> $permissions
22:  * @property-read int|null $permissions_count
23:  *
24:  * @method static \Database\Factories\Seller\TeamFactory factory($count = null, $state = [])
25:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Team newModelQuery()
26:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Team newQuery()
27:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Team query()
28:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereCreatedAt($value)
29:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereId($value)
30:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereSellerId($value)
31:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereUpdatedAt($value)
32:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereUserId($value)
33:  *
34:  * @mixin \Illuminate\Database\Eloquent\Model
35:  */
36: final class Team extends Model
37: {
38:     use HasFactory;
39: 
40:     /**
41:      * The table associated with the model.
42:      *
43:      * @var string
44:      */
45:     protected $table = 'sales_seller_teams';
46: 
47:     /**
48:      * The attributes that are mass assignable.
49:      *
50:      * @var list<string>
51:      */
52:     protected $fillable = [
53:         'user_id',
54:         'seller_id',
55:     ];
56: 
57:     /**
58:      * Get the user that owns this team member.
59:      */
60:     public function user(): BelongsTo
61:     {
62:         return $this->belongsTo(User::class);
63:     }
64: 
65:     /**
66:      * Get the seller that owns this team member.
67:      */
68:     public function seller(): BelongsTo
69:     {
70:         return $this->belongsTo(Seller::class);
71:     }
72: 
73:     /**
74:      * Get the permissions for this team member.
75:      */
76:     public function permissions(): HasMany
77:     {
78:         return $this->hasMany(TeamPermission::class);
79:     }
80: }
```

## Modules/Sales/app/Models/Seller/TeamPermission.php

SHA-256 `26487192f146b95d30b97e50f6662f20e45a358e09cccb13c1bc105b9ae54120`; 57 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Models\Seller;
6: 
7: use Illuminate\Database\Eloquent\Model;
8: use Illuminate\Database\Eloquent\Relations\BelongsTo;
9: use Sales\Enums\TeamPermission as TeamPermissionEnum;
10: 
11: /**
12:  * @property int $id
13:  * @property int $team_id
14:  * @property string $permission
15:  * @property-read Team $team
16:  *
17:  * @method static \Database\Factories\Seller\TeamPermissionFactory factory($count = null, $state = [])
18:  * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamPermission newModelQuery()
19:  * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamPermission newQuery()
20:  * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamPermission query()
21:  * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamPermission whereId($value)
22:  * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamPermission wherePermission($value)
23:  * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamPermission whereTeamId($value)
24:  *
25:  * @mixin \Illuminate\Database\Eloquent\Model
26:  */
27: final class TeamPermission extends Model
28: {
29:     public $timestamps = false;
30: 
31:     protected $table = 'sales_seller_team_permissions';
32: 
33:     /**
34:      * The attributes that are mass assignable.
35:      *
36:      * @var list<string>
37:      */
38:     protected $fillable = [
39:         'team_id',
40:         'permission',
41:     ];
42: 
43:     /**
44:      * Get the team member that owns this permission.
45:      */
46:     public function team(): BelongsTo
47:     {
48:         return $this->belongsTo(Team::class);
49:     }
50: 
51:     protected function casts(): array
52:     {
53:         return [
54:             'permission' => TeamPermissionEnum::class,
55:         ];
56:     }
57: }
```

## Modules/Sales/app/Models/Seller/Warehouse.php

SHA-256 `991f10277f12e7d40b33433dc40a88f142032ac2cc7bc5ff74b41eee590d3c01`; 64 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Models\Seller;
6: 
7: use App\Models\Geo\Location;
8: use Illuminate\Database\Eloquent\Model;
9: use Illuminate\Database\Eloquent\Relations\BelongsTo;
10: 
11: /**
12:  * @property int $id
13:  * @property string $name
14:  * @property int $seller_id
15:  * @property int $city_id
16:  * @property \Carbon\CarbonImmutable|null $created_at
17:  * @property \Carbon\CarbonImmutable|null $updated_at
18:  * @property-read Seller $seller
19:  * @property-read Location $city
20:  *
21:  * @method static \Database\Factories\Seller\WarehouseFactory factory($count = null, $state = [])
22:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Warehouse newModelQuery()
23:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Warehouse newQuery()
24:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Warehouse query()
25:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Warehouse whereCityId($value)
26:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Warehouse whereCreatedAt($value)
27:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Warehouse whereId($value)
28:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Warehouse whereName($value)
29:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Warehouse whereSellerId($value)
30:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Warehouse whereUpdatedAt($value)
31:  *
32:  * @mixin \Illuminate\Database\Eloquent\Model
33:  */
34: final class Warehouse extends Model
35: {
36:     protected $table = 'sales_seller_warehouses';
37: 
38:     /**
39:      * The attributes that are mass assignable.
40:      *
41:      * @var list<string>
42:      */
43:     protected $fillable = [
44:         'name',
45:         'seller_id',
46:         'city_id',
47:     ];
48: 
49:     /**
50:      * Get the seller that owns this warehouse.
51:      */
52:     public function seller(): BelongsTo
53:     {
54:         return $this->belongsTo(Seller::class, 'seller_id');
55:     }
56: 
57:     /**
58:      * Get the city where this warehouse is located.
59:      */
60:     public function city(): BelongsTo
61:     {
62:         return $this->belongsTo(Location::class, 'city_id');
63:     }
64: }
```

## Modules/Sales/app/Models/Shop/Info.php

SHA-256 `69f6410c9f7d137950e3bb680dac46a7b4e3fe7fa1556f9c3f49f907a0dadf9f`; 80 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Models\Shop;
6: 
7: use Illuminate\Database\Eloquent\Model;
8: use Illuminate\Database\Eloquent\Relations\BelongsTo;
9: 
10: /**
11:  * @property int $id
12:  * @property int $shop_id
13:  * @property string $name
14:  * @property string|null $customer_email
15:  * @property string|null $customer_phone
16:  * @property \Carbon\CarbonImmutable|null $verified_at
17:  * @property \Carbon\CarbonImmutable|null $created_at
18:  * @property \Carbon\CarbonImmutable|null $updated_at
19:  * @property-read Shop $shop
20:  *
21:  * @method static \Database\Factories\Shop\InfoFactory factory($count = null, $state = [])
22:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Info newModelQuery()
23:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Info newQuery()
24:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Info query()
25:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Info whereCreatedAt($value)
26:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Info whereCustomerEmail($value)
27:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Info whereCustomerPhone($value)
28:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Info whereId($value)
29:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Info whereName($value)
30:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Info whereShopId($value)
31:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Info whereUpdatedAt($value)
32:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Info whereVerifiedAt($value)
33:  *
34:  * @mixin \Illuminate\Database\Eloquent\Model
35:  */
36: final class Info extends Model
37: {
38:     protected $table = 'sales_shop_info';
39: 
40:     /**
41:      * The attributes that are mass assignable.
42:      *
43:      * @var list<string>
44:      */
45:     protected $fillable = [
46:         'shop_id',
47:         'name',
48:         'customer_email',
49:         'customer_phone',
50:         'verified_at',
51:     ];
52: 
53:     /**
54:      * Get the shop that owns this info.
55:      */
56:     public function shop(): BelongsTo
57:     {
58:         return $this->belongsTo(Shop::class);
59:     }
60: 
61:     /**
62:      * Scope a query to only include verified shop info.
63:      */
64:     public function scopeVerified($query)
65:     {
66:         return $query->whereNotNull('verified_at');
67:     }
68: 
69:     /**
70:      * The attributes that should be cast.
71:      *
72:      * @return array<string, string>
73:      */
74:     protected function casts(): array
75:     {
76:         return [
77:             'verified_at' => 'date',
78:         ];
79:     }
80: }
```

## Modules/Sales/app/Models/Shop/Shop.php

SHA-256 `3a29324d87922a59082f3e04b7f692d8ce64d44f322061aded95d0125a6f71fe`; 144 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Models\Shop;
6: 
7: use Buyer\Models\Order\Order;
8: use Catalog\Models\Product\Product;
9: use Illuminate\Database\Eloquent\Factories\HasFactory;
10: use Illuminate\Database\Eloquent\Model;
11: use Illuminate\Database\Eloquent\Relations\BelongsTo;
12: use Illuminate\Database\Eloquent\Relations\HasMany;
13: use Illuminate\Database\Eloquent\Relations\HasOne;
14: use Illuminate\Database\Eloquent\SoftDeletes;
15: use Sales\Models\Seller\Seller;
16: use Spatie\Sluggable\HasSlug;
17: use Spatie\Sluggable\SlugOptions;
18: 
19: /**
20:  * @property int $id
21:  * @property int $seller_id
22:  * @property string $name
23:  * @property string $slug
24:  * @property bool $is_active
25:  * @property \Carbon\CarbonImmutable|null $created_at
26:  * @property \Carbon\CarbonImmutable|null $updated_at
27:  * @property \Carbon\CarbonImmutable|null $deleted_at
28:  * @property-read Seller $seller
29:  * @property-read \Illuminate\Database\Eloquent\Collection<int, Product> $products
30:  * @property-read int|null $products_count
31:  * @property-read \Illuminate\Database\Eloquent\Collection<int, Order> $orders
32:  * @property-read int|null $orders_count
33:  *
34:  * @method static \Sales\Database\Factories\Shop\ShopFactory factory($count = null, $state = [])
35:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Shop newModelQuery()
36:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Shop newQuery()
37:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Shop query()
38:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Shop whereCreatedAt($value)
39:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Shop whereDeletedAt($value)
40:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Shop whereId($value)
41:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Shop whereIsActive($value)
42:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Shop whereName($value)
43:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Shop whereSellerId($value)
44:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Shop whereSlug($value)
45:  * @method static \Illuminate\Database\Eloquent\Builder<static>|Shop whereUpdatedAt($value)
46:  *
47:  * @mixin \Illuminate\Database\Eloquent\Model
48:  */
49: final class Shop extends Model
50: {
51:     use HasFactory, HasSlug, SoftDeletes;
52: 
53:     protected $table = 'sales_shops';
54: 
55:     /**
56:      * The attributes that are mass assignable.
57:      *
58:      * @var list<string>
59:      */
60:     protected $fillable = [
61:         'seller_id',
62:         'name',
63:         'slug',
64:         'is_active',
65:     ];
66: 
67:     /**
68:      * Get the options for generating the slug.
69:      */
70:     public function getSlugOptions(): SlugOptions
71:     {
72:         return SlugOptions::create()
73:             ->generateSlugsFrom('name')
74:             ->saveSlugsTo('slug');
75:     }
76: 
77:     /**
78:      * Get the seller that owns this shop.
79:      */
80:     public function seller(): BelongsTo
81:     {
82:         return $this->belongsTo(Seller::class);
83:     }
84: 
85:     /**
86:      * Get the products for this shop.
87:      */
88:     public function products(): HasMany
89:     {
90:         return $this->hasMany(Product::class);
91:     }
92: 
93:     /**
94:      * Get the orders for this shop.
95:      */
96:     public function orders(): HasMany
97:     {
98:         return $this->hasMany(Order::class);
99:     }
100: 
101:     /**
102:      * Get the info for this shop.
103:      */
104:     public function info(): HasOne
105:     {
106:         return $this->hasOne(Info::class);
107:     }
108: 
109:     /**
110:      * Get the collections for this shop.
111:      */
112:     public function collections(): HasMany
113:     {
114:         return $this->hasMany(\Catalog\Models\Collection\Collection::class);
115:     }
116: 
117:     /**
118:      * Scope a query to only include active shops.
119:      */
120:     public function scopeActive($query)
121:     {
122:         return $query->where('is_active', true);
123:     }
124: 
125:     /**
126:      * Create a new factory instance for the model.
127:      */
128:     protected static function newFactory()
129:     {
130:         return \Sales\Database\Factories\Shop\ShopFactory::new();
131:     }
132: 
133:     /**
134:      * The attributes that should be cast.
135:      *
136:      * @return array<string, string>
137:      */
138:     protected function casts(): array
139:     {
140:         return [
141:             'is_active' => 'boolean',
142:         ];
143:     }
144: }
```
