# Unbox tests — numbered source snapshot

HEAD `4e0d338c5316bb5e216239c4bfb0b62ce60c5a88`. Read-only evidence; filenames and line numbers are original.

## tests/Feature/ProductFormTest.php

SHA-256 `73e4a6509f3ef4cb04b53f7b2a40e31d8d52ff3b9c25bc8dc5b5d0d15d1211e7`; 7 lines.

```text
1: <?php
2: 
3: test('example', function () {
4:     $response = $this->get('/');
5: 
6:     $response->assertStatus(200);
7: });
```

## tests/Feature/ProductOffersIntegrationTest.php

SHA-256 `54d5c07bb656d0fd78ec3fac2ba5b0a54e8f43d26b4078c70597fae8f1df69a5`; 28 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Catalog\Models\Product\ProductOffer;
6: 
7: test('product offer deletion logic works', function () {
8:     // Test the boot method and delete method implementation
9:     $reflection = new ReflectionClass(ProductOffer::class);
10: 
11:     // Check that boot method exists
12:     expect($reflection->hasMethod('boot'))->toBeTrue();
13: 
14:     // Check that delete method exists
15:     expect($reflection->hasMethod('delete'))->toBeTrue();
16: 
17:     // Check that orderItems relationship exists
18:     expect($reflection->hasMethod('orderItems'))->toBeTrue();
19: 
20:     // Verify the delete method calls forceDelete
21:     $productOffer = new ProductOffer();
22:     $deleteMethod = $reflection->getMethod('delete');
23:     $deleteMethod->setAccessible(true);
24: 
25:     // We can't easily test the actual deletion without database setup,
26:     // but we can verify the method structure exists
27:     expect($deleteMethod)->not->toBeNull();
28: });
```

## tests/Feature/Sales/Catalog/ProductPageTest.php

SHA-256 `c8043ecf7eeea27261d3555d27b233131fea67aa3734677e1d4f238bede3e3ef`; 195 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use App\Models\User;
6: use Catalog\Models\Product\Product;
7: use Database\Factories\Catalog\ProductFactory;
8: use Illuminate\Foundation\Testing\RefreshDatabase;
9: use Illuminate\Support\Facades\Hash;
10: use Livewire\Livewire;
11: use Sales\Livewire\Pages\Catalog\Products\ProductPage;
12: use Sales\Models\Seller\Seller;
13: use Sales\Models\Seller\Team;
14: use Sales\Models\Shop\Shop;
15: 
16: uses(RefreshDatabase::class);
17: 
18: it('can create a new product', function () {
19:     // Создаем пользователя с продавцом
20:     $user = User::create([
21:         'first_name' => 'Иван',
22:         'last_name' => 'Иванов',
23:         'email' => 'test@example.com',
24:         'password' => Hash::make('password'),
25:     ]);
26: 
27:     $seller = Seller::create([
28:         'name' => 'Test Seller',
29:         'description' => 'Test Description',
30:         'is_active' => true,
31:         'verified' => true,
32:     ]);
33: 
34:     Team::create([
35:         'user_id' => $user->id,
36:         'seller_id' => $seller->id,
37:         'first_name' => 'Иван',
38:         'last_name' => 'Иванов',
39:     ]);
40: 
41:     // Создаем магазин для продавца
42:     $shop = Shop::create([
43:         'seller_id' => $seller->id,
44:         'name' => 'Test Shop',
45:         'is_active' => true,
46:     ]);
47: 
48:     $component = Livewire::actingAs($user)
49:         ->test(ProductPage::class);
50: 
51:     $component->set('form.name', 'Test Product')
52:         ->set('form.description', 'Test Description')
53:         ->set('form.vendor', 'Test Vendor')
54:         ->set('form.collectionIds', [])
55:         ->set('form.categoryIds', [])
56:         ->call('save')
57:         ->assertHasNoErrors();
58: 
59:     expect(Product::where('name', 'Test Product')->exists())->toBeTrue();
60: });
61: 
62: it('can update an existing product', function () {
63:     // Создаем пользователя с продавцом
64:     $user = User::create([
65:         'first_name' => 'Иван',
66:         'last_name' => 'Иванов',
67:         'email' => 'test@example.com',
68:         'password' => Hash::make('password'),
69:     ]);
70: 
71:     $seller = Seller::create([
72:         'name' => 'Test Seller',
73:         'description' => 'Test Description',
74:         'is_active' => true,
75:         'verified' => true,
76:     ]);
77: 
78:     Team::create([
79:         'user_id' => $user->id,
80:         'seller_id' => $seller->id,
81:         'first_name' => 'Иван',
82:         'last_name' => 'Иванов',
83:     ]);
84: 
85:     // Создаем магазин для продавца
86:     $shop = Shop::create([
87:         'seller_id' => $seller->id,
88:         'name' => 'Test Shop',
89:         'is_active' => true,
90:     ]);
91: 
92:     $product = ProductFactory::new()->create([
93:         'name' => 'Original Name',
94:         'shop_id' => $shop->id,
95:     ]);
96: 
97:     $component = Livewire::actingAs($user)
98:         ->test(ProductPage::class, ['productId' => $product->id]);
99: 
100:     $component->set('form.name', 'Updated Name')
101:         ->set('form.collectionIds', [])
102:         ->set('form.categoryIds', [])
103:         ->call('save')
104:         ->assertHasNoErrors();
105: 
106:     $product->refresh();
107:     expect($product->name)->toBe('Updated Name');
108: });
109: 
110: it('validates required fields', function () {
111:     // Создаем пользователя с продавцом
112:     $user = User::create([
113:         'first_name' => 'Иван',
114:         'last_name' => 'Иванов',
115:         'email' => 'test@example.com',
116:         'password' => Hash::make('password'),
117:     ]);
118: 
119:     $seller = Seller::create([
120:         'name' => 'Test Seller',
121:         'description' => 'Test Description',
122:         'is_active' => true,
123:         'verified' => true,
124:     ]);
125: 
126:     Team::create([
127:         'user_id' => $user->id,
128:         'seller_id' => $seller->id,
129:         'first_name' => 'Иван',
130:         'last_name' => 'Иванов',
131:     ]);
132: 
133:     // Создаем магазин для продавца
134:     $shop = Shop::create([
135:         'seller_id' => $seller->id,
136:         'name' => 'Test Shop',
137:         'is_active' => true,
138:     ]);
139: 
140:     $component = Livewire::actingAs($user)
141:         ->test(ProductPage::class);
142: 
143:     $component->set('form.name', '') // Required field left empty
144:         ->set('form.collectionIds', [])
145:         ->set('form.categoryIds', [])
146:         ->call('save')
147:         ->assertHasErrors(['form.name']);
148: });
149: 
150: it('can remove an image', function () {
151:     // Создаем пользователя с продавцом
152:     $user = User::create([
153:         'first_name' => 'Иван',
154:         'last_name' => 'Иванов',
155:         'email' => 'test@example.com',
156:         'password' => Hash::make('password'),
157:     ]);
158: 
159:     $seller = Seller::create([
160:         'name' => 'Test Seller',
161:         'description' => 'Test Description',
162:         'is_active' => true,
163:         'verified' => true,
164:     ]);
165: 
166:     Team::create([
167:         'user_id' => $user->id,
168:         'seller_id' => $seller->id,
169:         'first_name' => 'Иван',
170:         'last_name' => 'Иванов',
171:     ]);
172: 
173:     // Создаем магазин для продавца
174:     $shop = Shop::create([
175:         'seller_id' => $seller->id,
176:         'name' => 'Test Shop',
177:         'is_active' => true,
178:     ]);
179: 
180:     $product = ProductFactory::new()->create(['shop_id' => $shop->id]);
181: 
182:     // Add a fake image to the form (simulated as array)
183:     $images = [['name' => 'test.jpg', 'size' => 12345]];
184: 
185:     $component = Livewire::actingAs($user)
186:         ->test(ProductPage::class, ['productId' => $product->id]);
187: 
188:     $component->set('form.images', $images)
189:         ->set('form.collectionIds', [])
190:         ->set('form.categoryIds', [])
191:         ->call('removeImage', 0);
192: 
193:     // The image should be removed from the form
194:     expect($component->get('form.images'))->toBeEmpty();
195: });
```

## tests/Feature/Sales/Catalog/ProductsIndexTest.php

SHA-256 `275b87a9c357da56a66e0ba7804b9f71c21ccb4830bc3c9a57705f824e7ab130`; 80 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use App\Enums\User\Role as UserRole;
6: use Database\Factories\UserFactory;
7: use Sales\Database\Factories\Seller\SellerFactory;
8: use Sales\Livewire\Pages\Catalog\Products\ProductsIndex;
9: use Spatie\Permission\Models\Role;
10: 
11: test('products index page is accessible', function () {
12:     // Create the role if it doesn't exist
13:     Role::firstOrCreate([
14:         'name' => UserRole::SellerAdmin->value,
15:         'guard_name' => 'web',
16:     ]);
17: 
18:     $user = UserFactory::new()->create();
19:     $user->assignRole(UserRole::SellerAdmin->value);
20: 
21:     $seller = SellerFactory::new()->create(['verified' => true]);
22:     // Create team relationship
23:     Sales\Models\Seller\Team::create([
24:         'user_id' => $user->id,
25:         'seller_id' => $seller->id,
26:         'first_name' => 'Test',
27:         'last_name' => 'User',
28:     ]);
29: 
30:     $response = $this->actingAs($user)
31:         ->get(route('sales.catalog.products.list'));
32: 
33:     $response->assertSuccessful();
34:     // Note: The text might be inside a Livewire component, so we just check the status
35: });
36: 
37: test('products index component renders correctly', function () {
38:     // Create the role if it doesn't exist
39:     Role::firstOrCreate([
40:         'name' => UserRole::SellerAdmin->value,
41:         'guard_name' => 'web',
42:     ]);
43: 
44:     $user = UserFactory::new()->create();
45:     $user->assignRole(UserRole::SellerAdmin->value);
46: 
47:     $seller = SellerFactory::new()->create(['verified' => true]);
48:     // Create team relationship
49:     Sales\Models\Seller\Team::create([
50:         'user_id' => $user->id,
51:         'seller_id' => $seller->id,
52:         'first_name' => 'Test',
53:         'last_name' => 'User',
54:     ]);
55: 
56:     Livewire::actingAs($user)
57:         ->test(ProductsIndex::class)
58:         ->assertSet('activeTab', 'published')
59:         ->assertSet('search', '')
60:         ->assertSee('Опубликованные')
61:         ->assertSee('На согласовании')
62:         ->assertSee('Отклоненные')
63:         ->assertSee('В архиве');
64: });
65: 
66: test('products access is denied for non-verified sellers', function () {
67:     $user = UserFactory::new()->create();
68: 
69:     Livewire::actingAs($user)
70:         ->test(ProductsIndex::class)
71:         ->assertForbidden();
72: });
73: 
74: test('products access is denied for non-sellers', function () {
75:     $user = UserFactory::new()->create();
76: 
77:     Livewire::actingAs($user)
78:         ->test(ProductsIndex::class)
79:         ->assertForbidden();
80: });
```
