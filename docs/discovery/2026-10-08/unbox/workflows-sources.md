# Unbox workflows — numbered source snapshot

HEAD `4e0d338c5316bb5e216239c4bfb0b62ce60c5a88`. Read-only evidence; filenames and line numbers are original.

## Modules/Buyer/app/Filament/Resources/Orders/Schemas/OrderForm.php

SHA-256 `a763cdc0c4d03397a4e0b8ca29afe00e7eaef933be36debec659c9de7d3788e1`; 80 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Buyer\Filament\Resources\Orders\Schemas;
6: 
7: use Buyer\Enums\Order\PaymentMethod;
8: use Buyer\Enums\Order\PaymentStatus;
9: use Buyer\Enums\Order\Status;
10: use Filament\Forms\Components\DateTimePicker;
11: use Filament\Forms\Components\Select;
12: use Filament\Forms\Components\Textarea;
13: use Filament\Forms\Components\TextInput;
14: use Filament\Schemas\Schema;
15: 
16: final class OrderForm
17: {
18:     public static function configure(Schema $schema): Schema
19:     {
20:         return $schema
21:             ->components([
22:                 Select::make('user_id')
23:                     ->relationship('user', 'first_name')
24:                     ->getOptionLabelUsing(fn ($value): ?string => \App\Models\User::find($value)?->full_name)
25:                     ->required(),
26:                 Select::make('shop_id')
27:                     ->relationship('shop', 'name')
28:                     ->required(),
29:                 Select::make('customer_id')
30:                     ->relationship('customer', 'id')
31:                     ->required(),
32:                 Select::make('delivery_address_id')
33:                     ->relationship('deliveryAddress', 'id')
34:                     ->required(),
35:                 TextInput::make('order_number')
36:                     ->required(),
37:                 Select::make('status')
38:                     ->options(Status::class)
39:                     ->required(),
40:                 Select::make('payment_method')
41:                     ->options(PaymentMethod::class)
42:                     ->required(),
43:                 Select::make('payment_status')
44:                     ->options(PaymentStatus::class)
45:                     ->required(),
46:                 TextInput::make('gift_card_amount')
47:                     ->required()
48:                     ->numeric()
49:                     ->default(0),
50:                 TextInput::make('total_price')
51:                     ->required()
52:                     ->numeric()
53:                     ->default(0),
54:                 TextInput::make('total_discount')
55:                     ->required()
56:                     ->numeric()
57:                     ->default(0),
58:                 TextInput::make('total_tax')
59:                     ->required()
60:                     ->numeric()
61:                     ->default(0),
62:                 TextInput::make('total_shipping')
63:                     ->required()
64:                     ->numeric()
65:                     ->default(0),
66:                 TextInput::make('total_shipping_discount')
67:                     ->required()
68:                     ->numeric()
69:                     ->default(0),
70:                 TextInput::make('total_shipping_tax')
71:                     ->required()
72:                     ->numeric()
73:                     ->default(0),
74:                 TextInput::make('tracking_number'),
75:                 DateTimePicker::make('pickup_date'),
76:                 Textarea::make('comment')
77:                     ->columnSpanFull(),
78:             ]);
79:     }
80: }
```

## Modules/Buyer/app/Livewire/Components/Cart/CartSlideOver.php

SHA-256 `220fb40d36d3ee0f2d92886576758b248147717819cfba6a7b2afc1cd3acdd47`; 156 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Buyer\Livewire\Components\Cart;
6: 
7: use Catalog\Models\Product\Product;
8: use Illuminate\Support\Arr;
9: use Livewire\Attributes\On;
10: use LukePOLO\LaraCart\Facades\LaraCart;
11: use LukePOLO\LaraCart\LaraCart as LaraCartCore;
12: use WireElements\Pro\Components\SlideOver\SlideOver;
13: 
14: final class CartSlideOver extends SlideOver
15: {
16:     public static function attributes(): array
17:     {
18:         return [
19:             'size' => 'lg',
20:         ];
21:     }
22: 
23:     public function noop(): void
24:     {
25:         // No submit action.
26:     }
27: 
28:     #[On('cart-updated')]
29:     public function refreshCart(): void
30:     {
31:         // Trigger re-render.
32:     }
33: 
34:     public function removeItem(string $itemHash): void
35:     {
36:         LaraCart::removeItem($itemHash);
37: 
38:         $this->dispatch('cart-updated');
39:     }
40: 
41:     public function render()
42:     {
43:         return view('buyer::livewire.components.cart.cart-slide-over', [
44:             'items' => $this->getCartItems(),
45:             'total' => (string) LaraCart::total(),
46:         ]);
47:     }
48: 
49:     /**
50:      * @return array<int, array{
51:      *   hash: string,
52:      *   product_id: int|string|null,
53:      *   name: string,
54:      *   qty: int,
55:      *   price: string,
56:      *   subtotal: string,
57:      *   image_url: string|null,
58:      *   properties: array<int, string>
59:      * }>
60:      */
61:     private function getCartItems(): array
62:     {
63:         /** @var array<string, \LukePOLO\LaraCart\CartItem> $items */
64:         $items = Arr::wrap(LaraCart::getItems());
65: 
66:         $productIds = collect($items)
67:             ->map(fn ($item) => $item->id ?? null)
68:             ->filter()
69:             ->unique()
70:             ->values()
71:             ->all();
72: 
73:         $products = Product::query()
74:             ->with('media')
75:             ->whereIn('id', $productIds)
76:             ->get()
77:             ->keyBy('id');
78: 
79:         return collect($items)
80:             ->map(function ($item, $hash) use ($products) {
81:                 /** @var \LukePOLO\LaraCart\CartItem $item */
82:                 $product = $products->get($item->id);
83:                 $options = (array) ($item->options ?? []);
84: 
85:                 return [
86:                     'hash' => (string) $hash,
87:                     'product_id' => $item->id ?? null,
88:                     'name' => (string) ($item->name ?? 'Товар'),
89:                     'qty' => (int) ($item->qty ?? 1),
90:                     'price' => (string) LaraCartCore::formatMoney($item->price ?? 0),
91:                     'subtotal' => (string) LaraCartCore::formatMoney($item->subTotal() ?? 0),
92:                     'image_url' => $product?->getFirstMediaUrl('images') ?: null,
93:                     'properties' => $this->formatPropertyLines($options),
94:                 ];
95:             })
96:             ->values()
97:             ->all();
98:     }
99: 
100:     /**
101:      * @param  array<string, mixed>  $options
102:      * @return array<int, string>
103:      */
104:     private function formatPropertyLines(array $options): array
105:     {
106:         unset($options['model'], $options['qty']);
107: 
108:         $lines = [];
109: 
110:         $properties = $options['properties'] ?? null;
111:         if (is_array($properties)) {
112:             foreach ($properties as $property) {
113:                 if (is_string($property) && $property !== '') {
114:                     $lines[] = $property;
115: 
116:                     continue;
117:                 }
118: 
119:                 if (! is_array($property)) {
120:                     continue;
121:                 }
122: 
123:                 $name = $property['name'] ?? $property['label'] ?? null;
124:                 $value = $property['value'] ?? $property['selected'] ?? $property['code'] ?? null;
125: 
126:                 $name = is_string($name) ? mb_trim($name) : null;
127:                 $value = is_string($value) ? mb_trim($value) : (is_scalar($value) ? (string) $value : null);
128: 
129:                 if ($name && $value) {
130:                     $lines[] = "{$name}: {$value}";
131:                 } elseif ($value) {
132:                     $lines[] = $value;
133:                 }
134:             }
135: 
136:             return array_values(array_unique(array_filter($lines)));
137:         }
138: 
139:         foreach ($options as $key => $value) {
140:             if (! is_scalar($value)) {
141:                 continue;
142:             }
143: 
144:             $key = is_string($key) ? mb_trim($key) : (string) $key;
145:             $value = is_string($value) ? mb_trim($value) : (string) $value;
146: 
147:             if ($value === '') {
148:                 continue;
149:             }
150: 
151:             $lines[] = "{$key}: {$value}";
152:         }
153: 
154:         return array_values(array_unique($lines));
155:     }
156: }
```

## Modules/Buyer/app/Livewire/Components/CartWidget.php

SHA-256 `1953ab0d1a2cf5b029c1d1a2fc6da72e58c8f351ffc52473244a4c6e913e6746`; 82 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Buyer\Livewire\Components;
6: 
7: use Catalog\Models\Product\ProductOffer;
8: use Livewire\Attributes\On;
9: use Livewire\Component;
10: use LukePOLO\LaraCart\Facades\LaraCart;
11: 
12: final class CartWidget extends Component
13: {
14:     #[On('cart:add')]
15:     public function addToCart(?int $offerId = null): void
16:     {
17:         if (! $offerId) {
18:             $this->js("window.Flux?.toast('Не удалось добавить товар: не выбран оффер.', {variant: 'error'})");
19: 
20:             return;
21:         }
22: 
23:         $offer = ProductOffer::query()
24:             ->with([
25:                 'product',
26:                 'propertyValues.property',
27:             ])
28:             ->find($offerId);
29: 
30:         if (! $offer) {
31:             $this->js("window.Flux?.toast('Не удалось добавить товар: оффер не найден.', {variant: 'error'})");
32: 
33:             return;
34:         }
35: 
36:         $properties = $offer->propertyValues
37:             ->map(function ($value) {
38:                 $label = $value->label
39:                     ?? $value->value_text
40:                     ?? ($value->value_integer !== null ? (string) $value->value_integer : null)
41:                     ?? ($value->value_decimal !== null ? (string) $value->value_decimal : null)
42:                     ?? $value->code;
43: 
44:                 return [
45:                     'name' => $value->property?->name,
46:                     'value' => $label,
47:                     'code' => $value->code,
48:                 ];
49:             })
50:             ->filter(fn (array $row) => ! empty($row['value']))
51:             ->values()
52:             ->all();
53: 
54:         LaraCart::add(
55:             $offer->product_id,
56:             $offer->product?->name ?? 'Товар',
57:             1,
58:             (string) $offer->price,
59:             [
60:                 'offer_id' => $offer->id,
61:                 'properties' => $properties,
62:             ],
63:         );
64: 
65:         $this->dispatch('cart-updated');
66:         $this->js("window.Flux?.toast('Добавлено в корзину.', {variant: 'success'})");
67:     }
68: 
69:     #[On('cart-updated')]
70:     public function refreshCartWidget(): void
71:     {
72:         // Trigger re-render.
73:     }
74: 
75:     public function render()
76:     {
77:         return view('buyer::livewire.components.cart-widget', [
78:             'count' => (int) LaraCart::count(),
79:         ]);
80:     }
81: }
82: 
```

## Modules/Buyer/composer.json

SHA-256 `19b1a1352967dadb768d2d2d0cabd2882dc5f9b62bc944fc4053a741258d767f`; 30 lines.

```text
1: {
2:     "name": "nwidart/buyer",
3:     "description": "",
4:     "authors": [
5:         {
6:             "name": "Nicolas Widart",
7:             "email": "n.widart@gmail.com"
8:         }
9:     ],
10:     "extra": {
11:         "laravel": {
12:             "providers": [],
13:             "aliases": {
14: 
15:             }
16:         }
17:     },
18:     "autoload": {
19:         "psr-4": {
20:             "\\Buyer\\": "app/",
21:             "\\Buyer\\Database\\Factories\\": "database/factories/",
22:             "\\Buyer\\Database\\Seeders\\": "database/seeders/"
23:         }
24:     },
25:     "autoload-dev": {
26:         "psr-4": {
27:             "\\Buyer\\Tests\\": "tests/"
28:         }
29:     }
30: }
```

## Modules/Buyer/routes/web.php

SHA-256 `5df72d059c38ea8b4ac17e35a13a9ae89adaea2d07c40af335a5e21fbb8e91f3`; 10 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Buyer\Http\Controllers\BuyerController;
6: use Illuminate\Support\Facades\Route;
7: 
8: Route::middleware(['auth', 'verified'])->group(function () {
9:     Route::resource('buyers', BuyerController::class)->names('buyer');
10: });
```

## Modules/Catalog/app/Filament/Resources/Properties/Pages/CreateProperty.php

SHA-256 `f0f815680136a1d26d050c33472a547e3df483ccaeafa97886baca52b5be5147`; 13 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Filament\Resources\Properties\Pages;
6: 
7: use Catalog\Filament\Resources\Properties\PropertyResource;
8: use Filament\Resources\Pages\CreateRecord;
9: 
10: final class CreateProperty extends CreateRecord
11: {
12:     protected static string $resource = PropertyResource::class;
13: }
```

## Modules/Catalog/app/Filament/Resources/Properties/Pages/EditProperty.php

SHA-256 `48c0e2c37c4ade1dc2774b8fa49529533caabceff5c9f12642245c933722677d`; 21 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Filament\Resources\Properties\Pages;
6: 
7: use Catalog\Filament\Resources\Properties\PropertyResource;
8: use Filament\Actions\DeleteAction;
9: use Filament\Resources\Pages\EditRecord;
10: 
11: final class EditProperty extends EditRecord
12: {
13:     protected static string $resource = PropertyResource::class;
14: 
15:     protected function getHeaderActions(): array
16:     {
17:         return [
18:             DeleteAction::make(),
19:         ];
20:     }
21: }
```

## Modules/Catalog/app/Filament/Resources/Properties/Pages/ListProperties.php

SHA-256 `1472e5c5b42ea9ec888222ec4546e2cf3ddacbcd90e0c4bc6c98318b26d8308b`; 21 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Filament\Resources\Properties\Pages;
6: 
7: use Catalog\Filament\Resources\Properties\PropertyResource;
8: use Filament\Actions\CreateAction;
9: use Filament\Resources\Pages\ListRecords;
10: 
11: final class ListProperties extends ListRecords
12: {
13:     protected static string $resource = PropertyResource::class;
14: 
15:     protected function getHeaderActions(): array
16:     {
17:         return [
18:             CreateAction::make(),
19:         ];
20:     }
21: }
```

## Modules/Catalog/app/Filament/Resources/Properties/PropertyResource.php

SHA-256 `1d07db3c25c02882cbf0e7c7831e3b5747880474f6afa67a471e9deabeb00662`; 55 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Filament\Resources\Properties;
6: 
7: use Catalog\Filament\Resources\Properties\Pages\CreateProperty;
8: use Catalog\Filament\Resources\Properties\Pages\EditProperty;
9: use Catalog\Filament\Resources\Properties\Pages\ListProperties;
10: use Catalog\Filament\Resources\Properties\RelationManagers\PropertyValuesRelationManager;
11: use Catalog\Filament\Resources\Properties\Schemas\PropertyForm;
12: use Catalog\Filament\Resources\Properties\Tables\PropertiesTable;
13: use Catalog\Models\Property;
14: use Filament\Resources\Resource;
15: use Filament\Schemas\Schema;
16: use Filament\Tables\Table;
17: 
18: final class PropertyResource extends Resource
19: {
20:     protected static ?string $model = Property::class;
21: 
22:     protected static ?string $navigationLabel = 'Свойства';
23: 
24:     protected static ?string $breadcrumb = 'Свойства';
25: 
26:     protected static ?string $modelLabel = 'Свойство';
27: 
28:     protected static ?string $pluralLabel = 'Свойства';
29: 
30:     public static function form(Schema $schema): Schema
31:     {
32:         return PropertyForm::configure($schema);
33:     }
34: 
35:     public static function table(Table $table): Table
36:     {
37:         return PropertiesTable::configure($table);
38:     }
39: 
40:     public static function getRelations(): array
41:     {
42:         return [
43:             PropertyValuesRelationManager::class,
44:         ];
45:     }
46: 
47:     public static function getPages(): array
48:     {
49:         return [
50:             'index' => ListProperties::route('/'),
51:             'create' => CreateProperty::route('/create'),
52:             'edit' => EditProperty::route('/{record}/edit'),
53:         ];
54:     }
55: }
```

## Modules/Catalog/app/Filament/Resources/Properties/RelationManagers/PropertyValuesRelationManager.php

SHA-256 `b94309e551c1af0eeda02253e100b6bbb8fb3cd4db8f5b6c5ab91016bedce270`; 156 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Filament\Resources\Properties\RelationManagers;
6: 
7: use Catalog\Enums\Product\PropertyControl;
8: use Catalog\Enums\Product\PropertyType;
9: use Catalog\Models\Property;
10: use Filament\Actions\CreateAction;
11: use Filament\Actions\DeleteAction;
12: use Filament\Actions\EditAction;
13: use Filament\Forms\Components\ColorPicker;
14: use Filament\Forms\Components\TextInput;
15: use Filament\Resources\RelationManagers\RelationManager;
16: use Filament\Schemas\Components\Grid;
17: use Filament\Schemas\Schema;
18: use Filament\Tables\Columns\ColorColumn;
19: use Filament\Tables\Columns\TextColumn;
20: use Filament\Tables\Table;
21: 
22: final class PropertyValuesRelationManager extends RelationManager
23: {
24:     protected static string $relationship = 'values';
25: 
26:     protected static ?string $title = 'Значения';
27: 
28:     protected static ?string $recordTitleAttribute = 'label';
29: 
30:     public function form(Schema $schema): Schema
31:     {
32:         return $schema->components([
33:             Grid::make()
34:                 ->schema([
35:                     TextInput::make('label')
36:                         ->label('Название')
37:                         ->maxLength(32),
38:                     TextInput::make('code')
39:                         ->label('Код')
40:                         ->maxLength(32),
41:                     ...$this->getValueComponents(),
42:                 ]),
43:         ]);
44:     }
45: 
46:     public function table(Table $table): Table
47:     {
48:         return $table
49:             ->recordTitleAttribute('label')
50:             ->columns([
51:                 TextColumn::make('label')
52:                     ->label('Название')
53:                     ->searchable(),
54:                 TextColumn::make('code')
55:                     ->label('Код')
56:                     ->searchable(),
57:                 ...$this->getValueColumns(),
58:             ])
59:             ->headerActions([
60:                 CreateAction::make(),
61:             ])
62:             ->recordActions([
63:                 EditAction::make(),
64:                 DeleteAction::make(),
65:             ]);
66:     }
67: 
68:     public function mutateFormDataBeforeCreate(array $data): array
69:     {
70:         return $this->normalizeValueFields($data);
71:     }
72: 
73:     public function mutateFormDataBeforeSave(array $data): array
74:     {
75:         return $this->normalizeValueFields($data);
76:     }
77: 
78:     /**
79:      * @return list<\Filament\Forms\Components\Component>
80:      */
81:     private function getValueComponents(): array
82:     {
83:         $property = $this->getOwnerProperty();
84: 
85:         return match ($property->type) {
86:             PropertyType::Text => [
87:                 $property->control === PropertyControl::Colorpicker
88:                     ? ColorPicker::make('value_text')
89:                     ->label('Значение')
90:                     ->required()
91:                     : TextInput::make('value_text')
92:                     ->label('Значение')
93:                     ->required(),
94:             ],
95:             PropertyType::Integer => [
96:                 TextInput::make('value_integer')
97:                     ->label('Значение')
98:                     ->required()
99:                     ->integer(),
100:             ],
101:             PropertyType::Decimal => [
102:                 TextInput::make('value_decimal')
103:                     ->label('Значение')
104:                     ->required()
105:                     ->numeric(),
106:             ],
107:         };
108:     }
109: 
110:     /**
111:      * @return list<\Filament\Tables\Columns\Column>
112:      */
113:     private function getValueColumns(): array
114:     {
115:         $property = $this->getOwnerProperty();
116: 
117:         return match ($property->type) {
118:             PropertyType::Text => [
119:                 $property->control === PropertyControl::Colorpicker
120:                     ? ColorColumn::make('value_text')
121:                     ->label('Значение')
122:                     : TextColumn::make('value_text')
123:                     ->label('Значение'),
124:             ],
125:             PropertyType::Integer => [
126:                 TextColumn::make('value_integer')
127:                     ->label('Значение')
128:                     ->numeric(),
129:             ],
130:             PropertyType::Decimal => [
131:                 TextColumn::make('value_decimal')
132:                     ->label('Значение')
133:                     ->numeric(),
134:             ],
135:         };
136:     }
137: 
138:     private function normalizeValueFields(array $data): array
139:     {
140:         $property = $this->getOwnerProperty();
141: 
142:         $data['value_text'] = $property->type === PropertyType::Text ? ($data['value_text'] ?? null) : null;
143:         $data['value_integer'] = $property->type === PropertyType::Integer ? ($data['value_integer'] ?? null) : null;
144:         $data['value_decimal'] = $property->type === PropertyType::Decimal ? ($data['value_decimal'] ?? null) : null;
145: 
146:         return $data;
147:     }
148: 
149:     private function getOwnerProperty(): Property
150:     {
151:         /** @var Property $property */
152:         $property = $this->getOwnerRecord();
153: 
154:         return $property;
155:     }
156: }
```

## Modules/Catalog/app/Filament/Resources/Properties/Schemas/PropertyForm.php

SHA-256 `780095ab2f2fe8c5154447b578133ef1c8730dad309602be9cb3048bc5e37a05`; 64 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Filament\Resources\Properties\Schemas;
6: 
7: use Catalog\Enums\Product\PropertyControl;
8: use Catalog\Enums\Product\PropertyType;
9: use Catalog\Enums\Product\PropertyUnit;
10: use Filament\Forms\Components\Select;
11: use Filament\Forms\Components\TextInput;
12: use Filament\Schemas\Components\Grid;
13: use Filament\Schemas\Components\Section;
14: use Filament\Schemas\Schema;
15: 
16: final class PropertyForm
17: {
18:     public static function configure(Schema $schema): Schema
19:     {
20:         return $schema
21:             ->components([
22:                 Grid::make()
23:                     ->columns(8)
24:                     ->columnSpanFull()
25:                     ->schema([
26:                         Section::make('Основные данные')
27:                             ->columnSpan(6)
28:                             ->schema([
29:                                 TextInput::make('name')
30:                                     ->label('Название')
31:                                     ->required()
32:                                     ->maxLength(50),
33:                                 TextInput::make('code')
34:                                     ->label('Код')
35:                                     ->maxLength(60)
36:                                     ->disabledOn('edit'),
37:                                 Select::make('type')
38:                                     ->label('Тип значения')
39:                                     ->options(PropertyType::class)
40:                                     ->required()
41:                                     ->default(PropertyType::Text->value)
42:                                     ->disabledOn('edit'),
43:                                 Select::make('control')
44:                                     ->label('Элемент управления')
45:                                     ->options(PropertyControl::class)
46:                                     ->disabledOn('edit'),
47:                                 Select::make('unit')
48:                                     ->label('Единица измерения')
49:                                     ->options(PropertyUnit::class)
50:                                     ->disabledOn('edit'),
51:                             ]),
52:                         Section::make('')
53:                             ->columnSpan(2)
54:                             ->schema([
55:                                 TextInput::make('sort_order')
56:                                     ->label('Сортировка')
57:                                     ->numeric()
58:                                     ->required()
59:                                     ->default(1),
60:                             ]),
61:                     ]),
62:             ]);
63:     }
64: }
```

## Modules/Catalog/app/Filament/Resources/Properties/Tables/PropertiesTable.php

SHA-256 `0c214f6e598b8ee2faf9d7747cced259240a6ae9288e53b04e46c37c467471dc`; 57 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Filament\Resources\Properties\Tables;
6: 
7: use Catalog\Enums\Product\PropertyControl;
8: use Catalog\Enums\Product\PropertyType;
9: use Catalog\Enums\Product\PropertyUnit;
10: use Filament\Actions\DeleteBulkAction;
11: use Filament\Actions\EditAction;
12: use Filament\Tables\Columns\TextColumn;
13: use Filament\Tables\Table;
14: 
15: final class PropertiesTable
16: {
17:     public static function configure(Table $table): Table
18:     {
19:         return $table
20:             ->columns([
21:                 TextColumn::make('name')
22:                     ->label('Название')
23:                     ->searchable()
24:                     ->sortable(),
25:                 TextColumn::make('code')
26:                     ->label('Код')
27:                     ->searchable(),
28:                 TextColumn::make('type')
29:                     ->label('Тип')
30:                     ->badge()
31:                     ->formatStateUsing(
32:                         static fn ($state) => $state instanceof PropertyType ? $state->getLabel() : (filled($state) ? PropertyType::from($state)->getLabel() : null),
33:                     ),
34:                 TextColumn::make('control')
35:                     ->label('Элемент управления')
36:                     ->badge()
37:                     ->formatStateUsing(
38:                         static fn ($state) => $state instanceof PropertyControl ? $state->getLabel() : (filled($state) ? PropertyControl::from($state)->getLabel() : null),
39:                     ),
40:                 TextColumn::make('unit')
41:                     ->label('Ед. измерения')
42:                     ->formatStateUsing(
43:                         static fn ($state) => $state instanceof PropertyUnit ? $state->getLabel() : (filled($state) ? PropertyUnit::from($state)->getLabel() : null),
44:                     ),
45:                 TextColumn::make('sort_order')
46:                     ->label('Сортировка')
47:                     ->numeric()
48:                     ->sortable(),
49:             ])
50:             ->recordActions([
51:                 EditAction::make(),
52:             ])
53:             ->bulkActions([
54:                 DeleteBulkAction::make(),
55:             ]);
56:     }
57: }
```

## Modules/Catalog/app/Livewire/Components/Product/Card.php

SHA-256 `4185cdc29b0b8d2c72bc9021d54fb237f151accbfe165cf6184ac29c68160310`; 209 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Livewire\Components\Product;
6: 
7: use Catalog\Enums\Product\Condition;
8: use Catalog\Models\Product\Product;
9: use Illuminate\Support\Arr;
10: use Livewire\Attributes\Computed;
11: use Livewire\Attributes\On;
12: use Livewire\Component;
13: use LukePOLO\LaraCart\Facades\LaraCart;
14: 
15: final class Card extends Component
16: {
17:     public Product $product;
18: 
19:     public int $quantity = 1;
20: 
21:     #[Computed]
22:     public function imageUrl(): ?string
23:     {
24:         $url = $this->product->getFirstMediaUrl(Product::IMAGES_COLLECTION);
25: 
26:         return $url !== '' ? $url : null;
27:     }
28: 
29:     #[Computed]
30:     public function hoverImageUrl(): ?string
31:     {
32:         $url = $this->product->getFirstMediaUrl(Product::IMAGE_HOVER_COLLECTION);
33: 
34:         return $url !== '' ? $url : null;
35:     }
36: 
37:     #[Computed]
38:     public function conditionLabel(): string
39:     {
40:         if ($this->product->condition instanceof Condition) {
41:             if ($this->product->condition === \Catalog\Enums\Product\Condition::PreOwned) {
42:                 return $this->product->condition->getLabel();
43:             }
44:             return '';
45:         }
46: 
47:         if ($this->product->condition === null) {
48:             return '';
49:         }
50: 
51:         $conditionValue = is_string($this->product->condition)
52:             ? $this->product->condition
53:             : (is_object($this->product->condition) && isset($this->product->condition->value)
54:                 ? $this->product->condition->value
55:                 : '');
56: 
57:         return Condition::tryFrom($conditionValue)?->getLabel() ?? '';
58:     }
59: 
60:     #[Computed]
61:     public function priceLabel(): string
62:     {
63:         if ($this->product->price === null) {
64:             return '—';
65:         }
66: 
67:         return number_format((float) $this->product->price, 0, ',', ' ') . ' ₽';
68:     }
69: 
70:     #[Computed]
71:     public function cartItem(): ?array
72:     {
73:         $items = Arr::wrap(LaraCart::getItems());
74: 
75:         foreach ($items as $hash => $item) {
76:             if (($item->id ?? null) === $this->product->id) {
77:                 return [
78:                     'hash' => (string) $hash,
79:                     'qty' => (int) ($item->qty ?? 1),
80:                 ];
81:             }
82:         }
83: 
84:         return null;
85:     }
86: 
87:     #[Computed]
88:     public function isInCart(): bool
89:     {
90:         return $this->cartItem() !== null;
91:     }
92: 
93:     #[Computed]
94:     public function isFavorite(): bool
95:     {
96:         $favorites = session('favorites', []);
97:         return in_array($this->product->id, $favorites, true);
98:     }
99: 
100:     public function mount(): void
101:     {
102:         $cartItem = $this->cartItem();
103:         if ($cartItem) {
104:             $this->quantity = $cartItem['qty'];
105:         }
106:     }
107: 
108:     public function toggleFavorite(): void
109:     {
110:         $favorites = session('favorites', []);
111:         $productId = $this->product->id;
112: 
113:         if (in_array($productId, $favorites, true)) {
114:             $favorites = array_values(array_filter($favorites, fn($id) => $id !== $productId));
115:         } else {
116:             $favorites[] = $productId;
117:         }
118: 
119:         session(['favorites' => $favorites]);
120:     }
121: 
122:     public function addToCart(): void
123:     {
124:         $offer = $this->product->offers()->first();
125: 
126:         if ($offer) {
127:             $this->dispatch('cart:add', offerId: $offer->id);
128:         } else {
129:             // Если нет offer, добавляем товар напрямую
130:             LaraCart::add(
131:                 $this->product->id,
132:                 $this->product->name ?? 'Товар',
133:                 1,
134:                 (string) ($this->product->price ?? 0),
135:                 []
136:             );
137:         }
138: 
139:         $this->dispatch('cart-updated');
140:     }
141: 
142:     public function goToCheckout(): void
143:     {
144:         $this->js('console.log("Перейти к оплате")');
145:     }
146: 
147:     public function updateQuantity($quantity = null): void
148:     {
149:         $quantity = $quantity ?? $this->quantity;
150:         $quantity = (int) $quantity;
151: 
152:         if ($quantity < 1) {
153:             $quantity = 1;
154:         }
155: 
156:         $cartItem = $this->cartItem();
157:         if (! $cartItem) {
158:             return;
159:         }
160: 
161:         LaraCart::updateItem($cartItem['hash'], 'qty', $quantity);
162:         $this->quantity = $quantity;
163:         $this->dispatch('cart-updated');
164:     }
165: 
166:     public function incrementQuantity(): void
167:     {
168:         $this->updateQuantity($this->quantity + 1);
169:     }
170: 
171:     public function decrementQuantity(): void
172:     {
173:         if ($this->quantity <= 1) {
174:             // Если количество равно 1 или меньше, удаляем товар из корзины
175:             $cartItem = $this->cartItem();
176:             if ($cartItem) {
177:                 LaraCart::removeItem($cartItem['hash']);
178:                 $this->quantity = 1;
179:                 $this->dispatch('cart-updated');
180:             }
181:         } else {
182:             $this->updateQuantity($this->quantity - 1);
183:         }
184:     }
185: 
186:     public function updatedQuantity($value): void
187:     {
188:         $cartItem = $this->cartItem();
189:         if ($cartItem) {
190:             $this->updateQuantity((int) $value);
191:         }
192:     }
193: 
194:     #[On('cart-updated')]
195:     public function refreshCart(): void
196:     {
197:         $cartItem = $this->cartItem();
198:         if ($cartItem) {
199:             $this->quantity = $cartItem['qty'];
200:         } else {
201:             $this->quantity = 1;
202:         }
203:     }
204: 
205:     public function render()
206:     {
207:         return view('catalog::livewire.components.product.card');
208:     }
209: }
```

## Modules/Catalog/app/Observers/ProductObserver.php

SHA-256 `edc38927780ab2e0140be4723afdc7d1c8fbba25859855efa99ce0b095790eaa`; 57 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Observers;
6: 
7: use App\Enums\Common\Moderation\ModerationStatus;
8: use Catalog\Models\Product\Product;
9: 
10: final class ProductObserver
11: {
12:     /**
13:      * Handle the Product "created" event.
14:      */
15:     public function created(Product $product): void
16:     {
17:         /* $product->createModerationRecord([
18:             'name' => $product->name,
19:             'description' => $product->description,
20:             'brand_id' => $product->brand_id,
21:         ]);*/
22:     }
23: 
24:     /**
25:      * Handle the Product "updating" event.
26:      */
27:     public function updating(Product $product): void
28:     {
29:         /* $original = $product->getOriginal();
30: 
31:         // Проверяем, изменились ли поля name, description или vendor
32:         $changedFields = array_intersect_key($product->getDirty(), array_flip(['name', 'description', 'vendor']));
33: 
34:         if (! empty($changedFields)) {
35:             // Получаем последнюю модерацию
36:             $latestModeration = $product->moderations()
37:                 ->latest()
38:                 ->first();
39: 
40:             // Если последняя модерация pending и moderation_data пустая (первая модерация), ничего не делаем
41:             if (
42:                 $latestModeration &&
43:                 $latestModeration->status === ModerationStatus::Pending &&
44:                 empty($latestModeration->moderation_data)
45:             ) {
46:                 return;
47:             }
48: 
49:             // Создаем новую запись модерации
50:             $product->createModerationRecord([
51:                 'name' => $product->name,
52:                 'description' => $product->description,
53:                 'vendor' => $product->vendor,
54:             ]);
55:         }*/
56:     }
57: }
```

## Modules/Catalog/app/Repositories/ProductsRepository.php

SHA-256 `74e5a94e61ce8af54859144814d845daf377c47d43e19d25be06f2e9153ed9df`; 111 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Catalog\Repositories;
6: 
7: use Spatie\QueryBuilder\QueryBuilder;
8: use Catalog\Models\Product\Product;
9: use Illuminate\Support\Collection;
10: 
11: final class ProductsRepository
12: {
13:     public static function paginated(
14:         ?string $categorySlug,
15:         array $colors,
16:         ?string $condition,
17:         ?int $priceFrom,
18:         ?int $priceTo,
19:         string $sort,
20:         ?string $sale,
21:         int $page,
22:         int $perPage
23:     ): Collection {
24:         return self::filteredQuery(
25:             categorySlug: $categorySlug,
26:             colors: $colors,
27:             condition: $condition,
28:             priceFrom: $priceFrom,
29:             priceTo: $priceTo,
30:             sort: $sort,
31:             sale: $sale
32:         )
33:             ->forPage($page, $perPage)
34:             ->get();
35:     }
36: 
37:     public static function count(
38:         ?string $categorySlug,
39:         array $colors,
40:         ?string $condition,
41:         ?int $priceFrom,
42:         ?int $priceTo,
43:         string $sort,
44:         ?string $sale
45:     ): int {
46:         $builder = self::filteredQuery(
47:             categorySlug: $categorySlug,
48:             colors: $colors,
49:             condition: $condition,
50:             priceFrom: $priceFrom,
51:             priceTo: $priceTo,
52:             sort: $sort,
53:             sale: $sale
54:         );
55: 
56:         return (int) (clone $builder)->toBase()->getCountForPagination();
57:     }
58: 
59:     private static function filteredQuery(
60:         ?string $categorySlug,
61:         array $colors,
62:         ?string $condition,
63:         ?int $priceFrom,
64:         ?int $priceTo,
65:         string $sort,
66:         ?string $sale
67:     ): QueryBuilder {
68:         $builder = QueryBuilder::for(
69:             Product::query()
70:                 ->with('media')
71:         )->allowedFilters(['name']);
72: 
73:         if ($categorySlug) {
74:             $builder->whereHas('categories', fn($query) => $query->where('slug', $categorySlug));
75:         }
76: 
77:         if ($colors !== []) {
78:             $builder->whereHas('offers.propertyValues', function ($query) use ($colors) {
79:                 $query->whereIn('cat_property_values.code', $colors)
80:                     ->whereHas('property', fn($propertyQuery) => $propertyQuery->where('code', 'color'));
81:             });
82:         }
83: 
84:         if ($condition) {
85:             $builder->where('condition', $condition);
86:         }
87: 
88:         if ($priceFrom !== null) {
89:             $builder->where('price', '>=', $priceFrom);
90:         }
91: 
92:         if ($priceTo !== null) {
93:             $builder->where('price', '<=', $priceTo);
94:         }
95: 
96:         if ($sale === 'y') {
97:             $builder->where('is_sale', true);
98:         }
99: 
100:         return self::applySorting($builder, $sort);
101:     }
102: 
103:     private static function applySorting(QueryBuilder $builder, string $sort): QueryBuilder
104:     {
105:         return match ($sort) {
106:             'cheap' => $builder->orderBy('price')->orderByDesc('id'),
107:             'expensive' => $builder->orderByDesc('price')->orderByDesc('id'),
108:             default => $builder->orderByDesc('published_at')->orderByDesc('id'),
109:         };
110:     }
111: }
```

## Modules/Catalog/composer.json

SHA-256 `b3908323523b7384f59940789172eaa3fa75dfd2f162c5ff824e41a4e6267bf5`; 30 lines.

```text
1: {
2:     "name": "unbox/catalog",
3:     "description": "",
4:     "authors": [
5:         {
6:             "name": "Dmitry Vostrikov",
7:             "email": "vostrikovdv@yandex.ru"
8:         }
9:     ],
10:     "extra": {
11:         "laravel": {
12:             "providers": [],
13:             "aliases": {}
14:         }
15:     },
16:     "autoload": {
17:         "psr-4": {
18:             "Catalog\\": "app/",
19:             "Catalog\\Database\\Factories\\": "database/factories/",
20:             "Catalog\\Database\\Seeders\\": "database/seeders/",
21:             "Catalog\\Database\\Migrations\\": "database/migrations/"
22:         }
23:     },
24:     "autoload-dev": {
25:         "psr-4": {
26:             "Catalog\\Tests\\": "tests/"
27:         }
28:     }
29: }
30: 
```

## Modules/Catalog/routes/web.php

SHA-256 `2f91ca6275683149fbab83ec1a897c0948f16b3bbf56be75c203fbc60dff6a34`; 5 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Illuminate\Support\Facades\Route;
```

## Modules/Sales/app/Livewire/Components/Forms/Product/CharacteristicsForm.php

SHA-256 `80ed2341da3beb0bcdc3532f11f4de075792187a9b71cb8249f5c712205aeea1`; 41 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Livewire\Components\Forms\Product;
6: 
7: use Catalog\Models\Product\Product;
8: use Livewire\Component;
9: use Sales\Livewire\Forms\Product\CharacteristicsForm as CharacteristicsFormData;
10: 
11: final class CharacteristicsForm extends Component
12: {
13:     public CharacteristicsFormData $form;
14: 
15:     public Product $product;
16: 
17:     public bool $editable = true;
18: 
19:     public function mount(int $productId, bool $editable = true): void
20:     {
21:         $this->editable = $editable;
22:         $this->product = Product::findOrFail($productId);
23:         $this->form->fillFromProduct($this->product);
24:     }
25: 
26:     public function saveCharacteristics(): void
27:     {
28:         if (! $this->editable || ! $this->product) {
29:             return;
30:         }
31: 
32:         $this->form->save($this->product);
33:         $this->js("window.Flux?.toast('Характеристики успешно сохранены.', {variant: 'success'})");
34:     }
35: 
36:     public function render()
37:     {
38:         return view('sales::livewire.components.forms.product.characteristics-form');
39:     }
40: }
41: 
```

## Modules/Sales/app/Livewire/Components/Forms/Product/ClassificationForm.php

SHA-256 `a85c68e477e26b2982235731ed0f502f705e792520552d85254eb8e8e3905c14`; 43 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Livewire\Components\Forms\Product;
6: 
7: use Catalog\Models\Product\Product;
8: use Livewire\Component;
9: use Sales\Livewire\Forms\Product\ClassificationForm as ClassificationFormData;
10: 
11: final class ClassificationForm extends Component
12: {
13:     public ClassificationFormData $form;
14: 
15:     public Product $product;
16: 
17:     public bool $editable = true;
18: 
19:     public function mount(int $productId, bool $editable = true): void
20:     {
21:         $this->editable = $editable;
22:         $this->product = Product::findOrFail($productId);
23:         $this->form->productId = $this->product->id;
24:         $this->form->collectionIds = $this->product->collections()->pluck('cat_collections.id')->toArray();
25:         $this->form->categoryIds = $this->product->categories()->pluck('cat_categories.id')->toArray();
26:     }
27: 
28:     public function saveClassification(): void
29:     {
30:         if (! $this->editable || ! $this->product) {
31:             return;
32:         }
33: 
34:         $this->form->save($this->product);
35:         $this->js("window.Flux?.toast('Классификация успешно сохранена.', {variant: 'success'})");
36:     }
37: 
38:     public function render()
39:     {
40:         return view('sales::livewire.components.forms.product.classification-form');
41:     }
42: }
43: 
```

## Modules/Sales/app/Livewire/Components/Forms/Product/ContentForm.php

SHA-256 `be6129ec190c6c2445f532caebfbeee8bd53d5956e391e46a43428e0321f65bd`; 66 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Livewire\Components\Forms\Product;
6: 
7: use Catalog\Models\Product\Product;
8: use Livewire\Component;
9: use Sales\Livewire\Forms\Product\ContentForm as ContentFormData;
10: 
11: final class ContentForm extends Component
12: {
13:     public ContentFormData $form;
14: 
15:     public Product $product;
16: 
17:     public bool $editable = true;
18: 
19:     public function mount(int $productId, bool $editable = true): void
20:     {
21:         $this->editable = $editable;
22:         $this->product = Product::findOrFail($productId);
23:         $this->form->fillFromProduct($this->product);
24:     }
25: 
26:     public function addBlock(string $type): void
27:     {
28:         if (! $this->editable) {
29:             return;
30:         }
31: 
32:         $this->form->addBlock($type);
33:     }
34: 
35:     public function removeBlock(int $index): void
36:     {
37:         if (! $this->editable) {
38:             return;
39:         }
40: 
41:         $this->form->removeBlock($index);
42:     }
43: 
44:     public function saveContent(): void
45:     {
46:         if (! $this->editable || ! $this->product) {
47:             return;
48:         }
49: 
50:         $this->form->save($this->product);
51: 
52:         // Обновляем продукт из базы
53:         $this->product->refresh();
54:         $this->product->load('blocks');
55: 
56:         // Обновляем данные формы после сохранения
57:         $this->form->fillFromProduct($this->product);
58: 
59:         $this->js("window.Flux?.toast('Контент успешно сохранен.', {variant: 'success'})");
60:     }
61: 
62:     public function render()
63:     {
64:         return view('sales::livewire.components.forms.product.content-form');
65:     }
66: }
```

## Modules/Sales/app/Livewire/Components/Forms/Product/MediaForm.php

SHA-256 `dc06e7c03bce1d23c8f1c4589ac0700924f6edae36e81cf563551db7b941e0a2`; 53 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Livewire\Components\Forms\Product;
6: 
7: use Catalog\Models\Product\Product;
8: use Livewire\Component;
9: use Livewire\WithFileUploads;
10: use Sales\Livewire\Forms\Product\MediaForm as MediaFormData;
11: 
12: final class MediaForm extends Component
13: {
14:     use WithFileUploads;
15: 
16:     public MediaFormData $form;
17: 
18:     public Product $product;
19: 
20:     public bool $editable = true;
21: 
22:     public function mount(int $productId, bool $editable = true): void
23:     {
24:         $this->editable = $editable;
25:         $this->product = Product::findOrFail($productId);
26:         $this->form->fillImages($this->product->getMedia(Product::IMAGES_COLLECTION)->sortBy('order_column')->values()->all());
27:         $this->form->fillImageHover($this->product->getMedia(Product::IMAGE_HOVER_COLLECTION)->all());
28:     }
29: 
30:     public function saveMedia(): void
31:     {
32:         if (! $this->editable || ! $this->product) {
33:             return;
34:         }
35: 
36:         $this->form->save($this->product);
37: 
38:         // Обновляем продукт из базы, чтобы получить свежие данные
39:         $this->product->refresh();
40:         $this->product->load('media');
41: 
42:         // Обновляем данные формы после сохранения
43:         $this->form->fillImages($this->product->getMedia(Product::IMAGES_COLLECTION)->sortBy('order_column')->values()->all());
44:         $this->form->fillImageHover($this->product->getMedia(Product::IMAGE_HOVER_COLLECTION)->all());
45: 
46:         $this->js("window.Flux?.toast('Изображения успешно сохранены.', {variant: 'success'})");
47:     }
48: 
49:     public function render()
50:     {
51:         return view('sales::livewire.components.forms.product.media-form');
52:     }
53: }
```

## Modules/Sales/app/Livewire/Components/Forms/Product/OffersForm.php

SHA-256 `fa25e161143273719a8fd7975d1224fb26e0ddd882a2cf97b203e8d77c7641ae`; 91 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Livewire\Components\Forms\Product;
6: 
7: use Catalog\Models\Product\Product;
8: use Livewire\Component;
9: use Sales\Livewire\Forms\Product\OffersForm as OffersFormData;
10: use Sales\Livewire\Forms\Product\PropertiesForm as PropertiesFormData;
11: 
12: final class OffersForm extends Component
13: {
14:     public OffersFormData $form;
15: 
16:     public PropertiesFormData $formProperties;
17: 
18:     public Product $product;
19: 
20:     public bool $editable = true;
21: 
22:     public function mount(int $productId, bool $editable = true): void
23:     {
24:         $this->editable = $editable;
25:         $this->product = Product::findOrFail($productId);
26:         $this->form->fillFromProduct($this->product);
27:         $this->formProperties->fillFromProduct($this->product);
28:     }
29: 
30:     public function saveOffers(): void
31:     {
32:         if (! $this->editable || ! $this->product) {
33:             return;
34:         }
35: 
36:         $this->form->save($this->product);
37: 
38:         // Обновляем продукт из базы, чтобы получить свежие данные
39:         $this->product->refresh();
40: 
41:         // Обновляем данные формы после сохранения
42:         $this->form->fillFromProduct($this->product);
43: 
44:         $this->js("window.Flux?.toast('Товарные предложения успешно сохранены.', {variant: 'success'})");
45:     }
46: 
47:     public function addOffer(): void
48:     {
49:         $this->form->openOfferModalForCreate($this->formProperties->getProductPropertiesForOffers());
50:     }
51: 
52:     public function openOfferModal(int $index): void
53:     {
54:         $this->form->openOfferModal($index, $this->formProperties->getProductPropertiesForOffers());
55:     }
56: 
57:     public function saveOfferFromModal(): void
58:     {
59:         $this->form->saveOfferFromModal($this->formProperties->getProductPropertiesForOffers());
60:     }
61: 
62:     public function removeOffer(int $index): void
63:     {
64:         $this->form->removeOffer($index);
65:     }
66: 
67:     public function generateOffers(): void
68:     {
69:         $this->form->generateOffers($this->formProperties->getProductPropertiesForOffers());
70: 
71:         $this->js("window.Flux?.toast('Предложения сгенерированы.', {variant: 'success'})");
72:     }
73: 
74:     public function deleteAllOffers(): void
75:     {
76:         if (! $this->editable || ! $this->product) {
77:             return;
78:         }
79: 
80:         $this->product->offers()->delete();
81:         $this->form->offers = [];
82:         $this->form->fillFromProduct($this->product);
83: 
84:         $this->js("window.Flux?.toast('Все товарные предложения удалены.', {variant: 'success'})");
85:     }
86: 
87:     public function render()
88:     {
89:         return view('sales::livewire.components.forms.product.offers-form');
90:     }
91: }
```

## Modules/Sales/app/Livewire/Components/Forms/Product/ProductMainForm.php

SHA-256 `a9499f6eafb6ee7bf07f8620bbce8dacdd114ae253195e5070ee87c3d0d25014`; 54 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Livewire\Components\Forms\Product;
6: 
7: use Catalog\Models\Product\Product;
8: use Livewire\Component;
9: use Sales\Livewire\Forms\Product\ProductMainForm as ProductMainFormData;
10: 
11: final class ProductMainForm extends Component
12: {
13:     public ProductMainFormData $form;
14: 
15:     public ?Product $product = null;
16: 
17:     public bool $editable = true;
18: 
19:     public function mount(?int $productId = null, bool $editable = true): void
20:     {
21:         $this->editable = $editable;
22: 
23:         if ($productId) {
24:             $this->product = Product::findOrFail($productId);
25:             $this->form->fillFromProduct($this->product);
26:         }
27:     }
28: 
29:     public function saveMain(): void
30:     {
31:         if (! $this->editable) {
32:             return;
33:         }
34: 
35:         $wasNew = ! $this->product?->exists;
36:         $this->product = $this->form->save($this->product);
37: 
38:         if ($wasNew) {
39:             $this->redirect(
40:                 route('sales.catalog.products.edit', ['product' => $this->product->id, 'tab' => 'main']),
41:                 navigate: true,
42:             );
43: 
44:             return;
45:         }
46:         $this->js("window.Flux?.toast('Товар успешно обновлен.', {variant: 'success'})");
47: 
48:     }
49: 
50:     public function render()
51:     {
52:         return view('sales::livewire.components.forms.product.product-main-form');
53:     }
54: }
```

## Modules/Sales/app/Livewire/Components/Forms/Product/PropertiesForm.php

SHA-256 `49d43aece9663f81d3f306788c872e73df1075dcee6abcc8404aeb7109e83a86`; 87 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Livewire\Components\Forms\Product;
6: 
7: use Catalog\Models\Product\Product;
8: use Livewire\Component;
9: use Sales\Livewire\Forms\Product\PropertiesForm as PropertiesFormData;
10: 
11: final class PropertiesForm extends Component
12: {
13:     public PropertiesFormData $form;
14: 
15:     public ?Product $product = null;
16: 
17:     public bool $editable = true;
18: 
19:     public function mount(?int $productId = null, bool $editable = true): void
20:     {
21:         $this->editable = $editable;
22: 
23:         if ($productId) {
24:             $this->product = Product::findOrFail($productId);
25:             $this->form->fillFromProduct($this->product);
26:         }
27:     }
28: 
29:     public function saveProperties(): void
30:     {
31:         if (! $this->editable || ! $this->product) {
32:             return;
33:         }
34: 
35:         $this->form->save($this->product);
36:         
37:         // Обновляем продукт из базы, чтобы получить свежие данные
38:         $this->product->refresh();
39:         $this->product->load('properties.values');
40:         
41:         // Обновляем данные формы после сохранения
42:         $this->form->fillFromProduct($this->product);
43:         
44:         $this->js("window.Flux?.toast('Свойства успешно сохранены.', {variant: 'success'})");
45:     }
46: 
47:     public function openPropertyModalForCreate(): void
48:     {
49:         $this->form->openPropertyModalForCreate();
50:     }
51: 
52:     public function openPropertyModal(int $index): void
53:     {
54:         $this->form->openPropertyModal($index);
55:     }
56: 
57:     public function removeProperty(int $index): void
58:     {
59:         $this->form->removeProperty($index);
60:     }
61: 
62:     public function setPropertyDraftMode(string $mode): void
63:     {
64:         $this->form->setPropertyDraftMode($mode);
65:     }
66: 
67:     public function addDraftValue(): void
68:     {
69:         $this->form->addDraftValue();
70:     }
71: 
72:     public function removeDraftValue(int $valueIndex): void
73:     {
74:         $this->form->removeDraftValue($valueIndex);
75:     }
76: 
77:     public function savePropertyFromModal(): void
78:     {
79:         $this->form->savePropertyFromModal();
80:     }
81: 
82:     public function render()
83:     {
84:         return view('sales::livewire.components.forms.product.properties-form');
85:     }
86: }
87: 
```

## Modules/Sales/app/Livewire/Components/Forms/Product/SpecificationForm.php

SHA-256 `2f255e82098babac5252ab73feb3e9adfdfbce3a0cb0e0ae4295104784946139`; 169 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Livewire\Components\Forms\Product;
6: 
7: use Catalog\Models\Product\Product;
8: use Livewire\Component;
9: use Sales\Livewire\Forms\Product\SpecificationForm as SpecificationFormData;
10: 
11: final class SpecificationForm extends Component
12: {
13:     public SpecificationFormData $form;
14: 
15:     public ?Product $product = null;
16: 
17:     public bool $editable = true;
18: 
19:     public bool $showCreateGroupModal = false;
20: 
21:     public string $newGroupName = '';
22: 
23:     public function mount(?int $productId = null, bool $editable = true): void
24:     {
25:         $this->editable = $editable;
26: 
27:         if ($productId) {
28:             $this->product = Product::findOrFail($productId);
29:             $this->form->fillFromProduct($this->product);
30:         }
31:     }
32: 
33:     public function saveSpecifications(): void
34:     {
35:         if (! $this->editable || ! $this->product) {
36:             return;
37:         }
38: 
39:         $this->form->save($this->product);
40: 
41:         // Обновляем продукт из базы, чтобы получить свежие данные
42:         $this->product->refresh();
43: 
44:         // Обновляем данные формы после сохранения
45:         $this->form->fillFromProduct($this->product);
46: 
47:         $this->js("window.Flux?.toast('Справочники успешно сохранены.', {variant: 'success'})");
48:     }
49: 
50:     public function addGroup(): void
51:     {
52:         $this->form->groups[] = [
53:             'id' => null,
54:             'group_id' => null,
55:             'content' => [],
56:             'sort_order' => 500,
57:             'options' => [],
58:         ];
59:     }
60: 
61:     public function removeGroup(int $index): void
62:     {
63:         if (isset($this->form->groups[$index])) {
64:             unset($this->form->groups[$index]);
65:             $this->form->groups = array_values($this->form->groups);
66:         }
67:     }
68: 
69:     public function addOption(int $groupIndex): void
70:     {
71:         if (! isset($this->form->groups[$groupIndex])) {
72:             return;
73:         }
74: 
75:         if (! isset($this->form->groups[$groupIndex]['options'])) {
76:             $this->form->groups[$groupIndex]['options'] = [];
77:         }
78: 
79:         $this->form->groups[$groupIndex]['options'][] = [
80:             'id' => null,
81:             'name' => null,
82:             'value' => '',
83:             'sort_order' => 500,
84:         ];
85:     }
86: 
87:     public function removeOption(int $groupIndex, int $optionIndex): void
88:     {
89:         if (! isset($this->form->groups[$groupIndex]['options'][$optionIndex])) {
90:             return;
91:         }
92: 
93:         unset($this->form->groups[$groupIndex]['options'][$optionIndex]);
94:         $this->form->groups[$groupIndex]['options'] = array_values($this->form->groups[$groupIndex]['options']);
95:     }
96: 
97:     public function openCreateGroupModal(): void
98:     {
99:         $this->showCreateGroupModal = true;
100:         $this->newGroupName = '';
101:     }
102: 
103:     public function createGroup(): void
104:     {
105:         if (empty($this->newGroupName)) {
106:             return;
107:         }
108: 
109:         $groupId = $this->form->createSpecificationGroup($this->newGroupName);
110: 
111:         // Обновляем список доступных групп
112:         $this->form->fillFromProduct($this->product);
113: 
114:         // Находим текущую группу и устанавливаем созданный ID
115:         $lastIndex = count($this->form->groups) - 1;
116:         if ($lastIndex >= 0 && $this->form->groups[$lastIndex]['group_id'] === null) {
117:             $this->form->groups[$lastIndex]['group_id'] = $groupId;
118:         } else {
119:             // Если нет текущей группы, добавляем новую
120:             $this->form->groups[] = [
121:                 'id' => null,
122:                 'group_id' => $groupId,
123:                 'content' => [],
124:                 'sort_order' => 500,
125:                 'options' => [],
126:             ];
127:         }
128: 
129:         $this->showCreateGroupModal = false;
130:         $this->newGroupName = '';
131: 
132:         $this->js("window.Flux?.toast('Группа успешно создана.', {variant: 'success'})");
133:     }
134: 
135:     public function updatedFormGroups($value, $key): void
136:     {
137:         // Обрабатываем изменения в группах, особенно для content
138:         if (str_contains($key, '.content')) {
139:             // Извлекаем индекс группы из ключа (например, "0.content" -> 0)
140:             $parts = explode('.', $key);
141:             if (count($parts) >= 2 && is_numeric($parts[0])) {
142:                 $groupIndex = (int) $parts[0];
143: 
144:                 // Если content это строка (HTML от Flux Editor), нужно преобразовать в TipTap JSON
145:                 if (isset($this->form->groups[$groupIndex]['content'])) {
146:                     $content = $this->form->groups[$groupIndex]['content'];
147: 
148:                     // Если это строка и не пустая, пытаемся распарсить как JSON
149:                     if (is_string($content) && ! empty($content)) {
150:                         $decoded = json_decode($content, true);
151:                         if (json_last_error() === JSON_ERROR_NONE) {
152:                             $this->form->groups[$groupIndex]['content'] = $decoded;
153:                         }
154:                         // Если не JSON, возможно это HTML - оставляем как есть для обработки в normalizeContentData
155:                         // Flux Editor должен отправлять JSON, но на всякий случай оставляем
156: 
157:                     } elseif (empty($content)) {
158:                         $this->form->groups[$groupIndex]['content'] = [];
159:                     }
160:                 }
161:             }
162:         }
163:     }
164: 
165:     public function render()
166:     {
167:         return view('sales::livewire.components.forms.product.specification-form');
168:     }
169: }
```

## Modules/Sales/app/Livewire/Forms/Product/CharacteristicsForm.php

SHA-256 `04bf2cb961f34a8e78402317bb9ae66ef19bbc43b207d7ef1f77ec14cced808f`; 66 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Livewire\Forms\Product;
6: 
7: use Catalog\Models\Product\Product;
8: use Illuminate\Support\Facades\DB;
9: use Livewire\Form;
10: 
11: final class CharacteristicsForm extends Form
12: {
13:     public ?int $base_weight = null;
14: 
15:     public ?int $base_length = null;
16: 
17:     public ?int $base_width = null;
18: 
19:     public ?int $base_height = null;
20: 
21:     public int $measure_ratio = 1;
22: 
23:     public ?int $productId = null;
24: 
25:     public function fillFromProduct(?Product $product): void
26:     {
27:         if (! $product) {
28:             return;
29:         }
30: 
31:         $this->productId = $product->id;
32:         $this->base_weight = $product->base_weight;
33:         $this->base_length = $product->base_length;
34:         $this->base_width = $product->base_width;
35:         $this->base_height = $product->base_height;
36:         $this->measure_ratio = $product->measure_ratio;
37:     }
38: 
39:     public function rules(): array
40:     {
41:         return [
42:             'base_weight' => ['nullable', 'integer', 'min:0'],
43:             'base_length' => ['nullable', 'integer', 'min:0'],
44:             'base_width' => ['nullable', 'integer', 'min:0'],
45:             'base_height' => ['nullable', 'integer', 'min:0'],
46:             'measure_ratio' => ['required', 'integer', 'min:1'],
47:         ];
48:     }
49: 
50:     public function save(Product $product): void
51:     {
52:         $this->validate();
53: 
54:         DB::transaction(function () use ($product) {
55:             $product->update([
56:                 'base_weight' => $this->base_weight,
57:                 'base_length' => $this->base_length,
58:                 'base_width' => $this->base_width,
59:                 'base_height' => $this->base_height,
60:                 'measure_ratio' => $this->measure_ratio,
61:             ]);
62: 
63:             $this->productId = $product->id;
64:         });
65:     }
66: }
```

## Modules/Sales/app/Livewire/Forms/Product/ClassificationForm.php

SHA-256 `53b68b84624b41588a0b3cf2d4dd73bac5b24547f56ba6b0fbf0f0cf13a2ed86`; 58 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Livewire\Forms\Product;
6: 
7: use Catalog\Models\Category;
8: use Catalog\Models\Product\Product;
9: use Illuminate\Support\Facades\Auth;
10: use Illuminate\Support\Facades\DB;
11: use Livewire\Form;
12: use Catalog\Models\Collection\Collection;
13: 
14: final class ClassificationForm extends Form
15: {
16:     public array $collectionIds = [];
17: 
18:     public array $categoryIds = [];
19: 
20:     public ?int $productId = null;
21: 
22:     public function rules(): array
23:     {
24:         return [
25:             'collectionIds' => ['nullable', 'array'],
26:             'categoryIds' => ['nullable', 'array'],
27:         ];
28:     }
29: 
30:     public function getAvailableCollections()
31:     {
32:         $user = Auth::user();
33:         if (! $user || ! $user->seller) {
34:             return collect();
35:         }
36: 
37:         return Collection::whereHas('shop', function ($query) use ($user) {
38:             $query->where('seller_id', $user->seller->id);
39:         })->active()->get();
40:     }
41: 
42:     public function getAvailableCategories()
43:     {
44:         return Category::active()->get();
45:     }
46: 
47:     public function save(Product $product): void
48:     {
49:         $this->validate();
50: 
51:         DB::transaction(function () use ($product) {
52:             $product->collections()->sync($this->collectionIds);
53:             $product->categories()->sync($this->categoryIds);
54: 
55:             $this->productId = $product->id;
56:         });
57:     }
58: }
```

## Modules/Sales/app/Livewire/Forms/Product/ContentForm.php

SHA-256 `3b30e3967bc9c9ad86bc88708a8993e696fb5b53345771864b7fa0daa5e312b7`; 204 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Livewire\Forms\Product;
6: 
7: use App\Support\FileEditorHelper;
8: use Catalog\Enums\Product\BlockType;
9: use Catalog\Models\Product\Product;
10: use Catalog\Models\Product\ProductBlock;
11: use Livewire\Form;
12: 
13: final class ContentForm extends Form
14: {
15:     /**
16:      * @var array<int, array{id: int|null, type: string, title: string|null, content: array|null, image: array, video: array, sort_order: int}>
17:      */
18:     public array $blocks = [];
19: 
20:     public ?string $description = '';
21: 
22:     public ?string $subtitle = '';
23: 
24:     public ?int $productId = null;
25: 
26:     public function rules(): array
27:     {
28:         return [
29:             'description' => ['nullable', 'string', 'max:1000'],
30:             'subtitle' => ['nullable', 'string', 'max:50'],
31:             'blocks' => ['nullable', 'array'],
32:             'blocks.*.type' => ['required', 'string', 'in:text,image,video'],
33:             'blocks.*.title' => ['nullable', 'string', 'max:255'],
34:             'blocks.*.content' => ['nullable'], // HTML строка от flux editor
35:             'blocks.*.image' => ['nullable', 'array'],
36:             'blocks.*.video' => ['nullable', 'array'],
37:         ];
38:     }
39: 
40:     public function fillFromProduct(Product $product): void
41:     {
42:         $this->productId = $product->id;
43:         $this->description = $product->description;
44:         $this->subtitle = $product->subtitle;
45:         $this->blocks = $product->blocks()
46:             ->orderBy('sort_order')
47:             ->get()
48:             ->map(function (ProductBlock $block) use ($product) {
49:                 $content = $block->content ?? [];
50: 
51:                 $data = [
52:                     'id' => $block->id,
53:                     'type' => $block->type->value,
54:                     'title' => $block->title,
55:                     'content' => $content, // tiptap JSON для редактора
56:                     'image' => [],
57:                     'video' => [],
58:                     'sort_order' => $block->sort_order,
59:                 ];
60: 
61:                 // Загружаем медиа файлы из коллекций для блока
62:                 $mediaMapping = match ($block->type) {
63:                     BlockType::IMAGE => [
64:                         'image' => 'content-images',
65:                     ],
66:                     BlockType::VIDEO => [
67:                         'image' => 'content-images',
68:                         'video' => 'content-videos',
69:                     ],
70:                     default => [],
71:                 };
72: 
73:                 foreach ($mediaMapping as $field => $collectionName) {
74:                     // Используем уникальную коллекцию для каждого блока
75:                     $blockCollectionName = $collectionName . '-' . $block->id;
76:                     $media = $product->getMedia($blockCollectionName);
77:                     if ($media->isNotEmpty()) {
78:                         $data[$field] = $media->all();
79:                     }
80:                 }
81: 
82:                 return $data;
83:             })
84:             ->values()
85:             ->toArray();
86:     }
87: 
88:     public function addBlock(string $type): void
89:     {
90:         $maxSortOrder = collect($this->blocks)->max('sort_order') ?? 0;
91:         $this->blocks[] = [
92:             'id' => null,
93:             'type' => $type,
94:             'title' => null,
95:             'content' => null,
96:             'image' => [],
97:             'video' => [],
98:             'sort_order' => $maxSortOrder + 1,
99:         ];
100:     }
101: 
102:     public function removeBlock(int $index): void
103:     {
104:         if (! isset($this->blocks[$index])) {
105:             return;
106:         }
107: 
108:         unset($this->blocks[$index]);
109:         $this->blocks = array_values($this->blocks);
110:     }
111: 
112:     public function updatedBlocks(mixed $value, string $path): void
113:     {
114:         // Обработка обновления массивов файлов
115:         if (str_contains($path, '.image') || str_contains($path, '.video')) {
116:             $parts = explode('.', $path);
117:             $blockIndex = (int) $parts[1];
118:             $field = $parts[2] ?? null;
119: 
120:             if (! isset($this->blocks[$blockIndex]) || ! $field) {
121:                 return;
122:             }
123: 
124:             if (! is_array($value)) {
125:                 return;
126:             }
127: 
128:             $this->blocks[$blockIndex][$field] = array_values($value);
129:         }
130:     }
131: 
132:     public function save(Product $product): void
133:     {
134:         $this->validate();
135: 
136:         // Сохраняем description и subtitle
137:         $product->description = $this->description;
138:         $product->subtitle = $this->subtitle;
139:         $product->save();
140: 
141:         $existingBlockIds = collect($this->blocks)
142:             ->pluck('id')
143:             ->filter()
144:             ->toArray();
145: 
146:         // Удаляем блоки, которые были удалены из формы
147:         $product->blocks()
148:             ->whereNotIn('id', $existingBlockIds)
149:             ->delete();
150: 
151:         foreach ($this->blocks as $blockData) {
152:             $block = $blockData['id']
153:                 ? ProductBlock::find($blockData['id'])
154:                 : new ProductBlock();
155: 
156:             // Content приходит как tiptap JSON массив от Flux editor
157:             $content = $blockData['content'] ?? [];
158:             if (! is_array($content)) {
159:                 $content = [];
160:             }
161: 
162:             // Сначала сохраняем блок, чтобы получить ID
163:             $block->fill([
164:                 'product_id' => $product->id,
165:                 'type' => BlockType::from($blockData['type']),
166:                 'title' => $blockData['title'],
167:                 'content' => $content,
168:                 'sort_order' => $blockData['sort_order'],
169:             ]);
170: 
171:             $block->save();
172: 
173:             // Маппинг медиа полей: поле формы => коллекция
174:             $mediaMapping = match ($blockData['type']) {
175:                 BlockType::IMAGE->value => [
176:                     'image' => 'content-images',
177:                 ],
178:                 BlockType::VIDEO->value => [
179:                     'image' => 'content-images',
180:                     'video' => 'content-videos',
181:                 ],
182:                 default => [],
183:             };
184: 
185:             // Обработка всех медиа полей одинаково
186:             foreach ($mediaMapping as $field => $collectionName) {
187:                 $files = $blockData[$field] ?? [];
188: 
189:                 // Используем уникальную коллекцию для каждого блока
190:                 $blockCollectionName = $collectionName . '-' . $block->id;
191: 
192:                 // Обрабатываем файлы (добавляем новые, удаляем отсутствующие)
193:                 FileEditorHelper::processFiles(
194:                     model: $product,
195:                     collectionName: $blockCollectionName,
196:                     files: $files
197:                 );
198:             }
199:         }
200: 
201:         $this->productId = $product->id;
202:         $this->fillFromProduct($product->fresh());
203:     }
204: }
```

## Modules/Sales/app/Livewire/Forms/Product/MediaForm.php

SHA-256 `8e6c1323c8bbc3a0e705b8421a04fb4edb87b4aa7a9f0c09161ada665c46839c`; 80 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Livewire\Forms\Product;
6: 
7: use App\Support\FileEditorHelper;
8: use Catalog\Models\Product\Product;
9: use Livewire\Form;
10: 
11: final class MediaForm extends Form
12: {
13:     public array $images = [];
14: 
15:     public array $imageHover = [];
16: 
17:     public ?int $productId = null;
18: 
19:     public function rules(): array
20:     {
21:         return [
22:             'images' => ['nullable', 'array'],
23:             'imageHover' => ['nullable', 'array', 'max:1'],
24:         ];
25:     }
26: 
27:     public function fillImages(array $images): void
28:     {
29:         $this->images = $images;
30:     }
31: 
32:     public function fillImageHover(array $imageHover): void
33:     {
34:         $this->imageHover = $imageHover;
35:     }
36: 
37:     public function updatedImages(mixed $value): void
38:     {
39:         if (! is_array($value)) {
40:             return;
41:         }
42: 
43:         $this->images = array_filter($value, fn ($file) => ! is_string($file));
44:         $this->images = array_values($this->images);
45:     }
46: 
47:     public function updatedImageHover(mixed $value): void
48:     {
49:         if (! is_array($value)) {
50:             return;
51:         }
52: 
53:         // Ограничиваем до одного файла
54:         $filtered = array_filter($value, fn ($file) => ! is_string($file));
55:         $this->imageHover = array_slice(array_values($filtered), 0, 1);
56:     }
57: 
58:     public function save(Product $product): void
59:     {
60:         $this->validate();
61: 
62:         // Обрабатываем файлы (добавляем новые, удаляем отсутствующие)
63:         FileEditorHelper::processFiles(
64:             model: $product,
65:             collectionName: Product::IMAGES_COLLECTION,
66:             files: $this->images
67:         );
68: 
69:         // Обрабатываем hover изображение (только одно)
70:         FileEditorHelper::processFiles(
71:             model: $product,
72:             collectionName: Product::IMAGE_HOVER_COLLECTION,
73:             files: $this->imageHover
74:         );
75: 
76:         $this->productId = $product->id;
77:         $this->fillImages($product->getMedia(Product::IMAGES_COLLECTION)->all());
78:         $this->fillImageHover($product->getMedia(Product::IMAGE_HOVER_COLLECTION)->all());
79:     }
80: }
```

## Modules/Sales/app/Livewire/Forms/Product/OffersForm.php

SHA-256 `43c0075d46fe4468924edd4e3ef376976d5f82261e86ab0ff305b3eb84f5f6b2`; 549 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Livewire\Forms\Product;
6: 
7: use Catalog\Models\Product\Product;
8: use Catalog\Models\Product\ProductOffer;
9: use Livewire\Form;
10: 
11: final class OffersForm extends Form
12: {
13:     public ?float $price = null;
14: 
15:     public ?float $old_price = null;
16: 
17:     public string $updateOfferPrices = 'none';
18: 
19:     public array $offers = [];
20: 
21:     public ?int $productId = null;
22: 
23:     public array $offerDraft = [];
24: 
25:     public bool $showOfferModal = false;
26: 
27:     public ?int $offerModalIndex = null;
28: 
29:     public function rules(): array
30:     {
31:         return [
32:             'price' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
33:             'old_price' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
34:             'offers' => ['nullable', 'array'],
35:             'offers.*.price' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
36:             'offers.*.inventory_quantity' => ['nullable', 'integer', 'min:0'],
37:             'offers.*.is_active' => ['boolean'],
38:             'offers.*.values' => ['nullable', 'array'],
39:             'offers.*.values.*.property_id' => ['required', 'integer', 'exists:cat_properties,id'],
40:             'offers.*.values.*.property_value_id' => ['nullable', 'integer', 'exists:cat_property_values,id'],
41:             'offers.*.values.*.value_text' => ['nullable', 'string', 'max:255'],
42:         ];
43:     }
44: 
45:     public function fillFromProduct(?Product $product): void
46:     {
47:         if (! $product) {
48:             return;
49:         }
50: 
51:         $this->productId = $product->id;
52:         $this->price = $product->price !== null ? (float) $product->price : null;
53:         $this->old_price = $product->old_price !== null ? (float) $product->old_price : null;
54: 
55:         // Получаем свойства товара для правильного отображения offers
56:         $propertyIds = $product->properties()
57:             ->orderBy('cat_properties.sort_order')
58:             ->pluck('cat_properties.id')
59:             ->filter()
60:             ->values()
61:             ->toArray();
62: 
63:         // Загружаем существующие предложения товара
64:         $this->offers = $product->offers()
65:             ->with('propertyValues')
66:             ->get()
67:             ->map(function ($offer) use ($propertyIds) {
68:                 $values = [];
69: 
70:                 // Если у товара есть свойства, создаем структуру values для каждого свойства
71:                 if (! empty($propertyIds)) {
72:                     foreach ($propertyIds as $propertyId) {
73:                         $existingValue = $offer->propertyValues->firstWhere('property_id', $propertyId);
74:                         $values[] = [
75:                             'property_id' => $propertyId,
76:                             'property_value_id' => $existingValue?->id,
77:                             'value_text' => null,
78:                         ];
79:                     }
80:                 }
81: 
82:                 return [
83:                     'price' => $offer->price !== null ? (float) $offer->price : null,
84:                     'inventory_quantity' => $offer->inventory_quantity ?? null,
85:                     'is_active' => $offer->is_active ?? true,
86:                     'values' => $values,
87:                 ];
88:             })
89:             ->toArray();
90:     }
91: 
92:     public function save(Product $product): void
93:     {
94:         $this->validate();
95: 
96:         // Сохраняем цену товара
97:         $product->price = $this->price;
98:         $product->old_price = $this->old_price;
99:         $product->save();
100: 
101:         // Обновляем цены предложений в зависимости от выбранного варианта
102:         if ($this->price !== null && $this->updateOfferPrices !== 'none') {
103:             foreach ($this->offers as $index => $offerData) {
104:                 $currentPrice = $offerData['price'] ?? null;
105: 
106:                 if ($this->updateOfferPrices === 'all') {
107:                     // Обновить у всех - обновляем все цены на базовую цену
108:                     $this->offers[$index]['price'] = $this->price;
109:                 } elseif ($this->updateOfferPrices === 'new') {
110:                     // Обновить у новых - обновляем только если цена null или 0
111:                     if ($currentPrice === null || $currentPrice === 0) {
112:                         $this->offers[$index]['price'] = $this->price;
113:                     }
114:                 }
115:             }
116:         }
117:         // Проверяем уникальность комбинаций propertyValues
118:         $this->validateUniqueCombinations();
119: 
120:         // Получаем все существующие предложения товара
121:         $existingOffers = $product->offers()->with('propertyValues')->get();
122: 
123:         // Создаем мапу существующих предложений по ключу комбинации property_value_id
124:         $existingOffersMap = [];
125:         foreach ($existingOffers as $existingOffer) {
126:             $key = $this->getOfferKey($existingOffer->propertyValues);
127:             $existingOffersMap[$key] = $existingOffer;
128:         }
129: 
130:         // Отслеживаем, какие предложения были обработаны
131:         $processedKeys = [];
132: 
133:         // Обрабатываем предложения из формы
134:         foreach ($this->offers as $offerData) {
135:             // Получаем property_value_id из данных формы
136:             $propertyValueIds = collect($offerData['values'] ?? [])
137:                 ->pluck('property_value_id')
138:                 ->filter()
139:                 ->unique()
140:                 ->values()
141:                 ->toArray();
142: 
143:             // Создаем ключ для поиска существующего предложения
144:             $key = $this->getOfferKeyFromPropertyValueIds($propertyValueIds);
145: 
146:             if (isset($existingOffersMap[$key])) {
147:                 // Обновляем существующее предложение
148:                 $existingOffer = $existingOffersMap[$key];
149:                 $existingOffer->update([
150:                     'price' => $offerData['price'] ?? $this->price ?? 0,
151:                     'inventory_quantity' => $offerData['inventory_quantity'] ?? 0,
152:                     'is_active' => $offerData['is_active'] ?? true,
153:                 ]);
154: 
155:                 // Обновляем привязку значений свойств
156:                 if (! empty($propertyValueIds)) {
157:                     $existingOffer->propertyValues()->sync($propertyValueIds);
158:                 } else {
159:                     $existingOffer->propertyValues()->detach();
160:                 }
161: 
162:                 $processedKeys[] = $key;
163:             } else {
164:                 // Создаем новое предложение
165:                 $offer = ProductOffer::create([
166:                     'product_id' => $product->id,
167:                     'price' => $offerData['price'] ?? $this->price ?? 0,
168:                     'inventory_quantity' => $offerData['inventory_quantity'] ?? 0,
169:                     'is_active' => $offerData['is_active'] ?? true,
170:                 ]);
171: 
172:                 // Привязываем значения свойств к предложению (если есть)
173:                 if (! empty($propertyValueIds)) {
174:                     $offer->propertyValues()->sync($propertyValueIds);
175:                 }
176:             }
177:         }
178: 
179:         // Удаляем предложения, которых нет в форме
180:         foreach ($existingOffersMap as $key => $existingOffer) {
181:             if (! in_array($key, $processedKeys, true)) {
182:                 $existingOffer->delete();
183:             }
184:         }
185:     }
186: 
187:     public function addOffer(array $productPropertiesForOffers = []): void
188:     {
189:         $this->openOfferModalForCreate($productPropertiesForOffers);
190:     }
191: 
192:     public function openOfferModalForCreate(array $productPropertiesForOffers = []): void
193:     {
194:         $this->offerModalIndex = null;
195:         $this->resetOfferDraft($productPropertiesForOffers);
196:         $this->showOfferModal = true;
197:     }
198: 
199:     public function openOfferModal(int $index, array $productPropertiesForOffers = []): void
200:     {
201:         if (! isset($this->offers[$index])) {
202:             return;
203:         }
204: 
205:         $offer = $this->offers[$index];
206:         $this->offerModalIndex = $index;
207: 
208:         // Получаем свойства для правильной структуры values
209:         $properties = collect($productPropertiesForOffers);
210:         $offerValues = [];
211: 
212:         if ($properties->isNotEmpty()) {
213:             foreach ($properties as $property) {
214:                 $propertyId = $property['id'] ?? null;
215:                 $existingValue = collect($offer['values'] ?? [])->firstWhere('property_id', $propertyId);
216: 
217:                 $offerValues[] = [
218:                     'property_id' => $propertyId,
219:                     'property_value_id' => $existingValue['property_value_id'] ?? null,
220:                     'value_text' => $existingValue['value_text'] ?? null,
221:                 ];
222:             }
223:         }
224: 
225:         $this->offerDraft = [
226:             'price' => $offer['price'] ?? null,
227:             'inventory_quantity' => $offer['inventory_quantity'] ?? null,
228:             'is_active' => $offer['is_active'] ?? true,
229:             'values' => $offerValues,
230:         ];
231: 
232:         $this->showOfferModal = true;
233:     }
234: 
235:     public function saveOfferFromModal(array $productPropertiesForOffers = []): void
236:     {
237:         $properties = collect($productPropertiesForOffers);
238: 
239:         // Создаем структуру values для всех свойств
240:         $offerValues = $properties->map(function ($property) {
241:             $propertyId = $property['id'] ?? null;
242:             $existingValue = collect($this->offerDraft['values'] ?? [])->firstWhere('property_id', $propertyId);
243: 
244:             return [
245:                 'property_id' => $propertyId,
246:                 'property_value_id' => $existingValue['property_value_id'] ?? null,
247:                 'value_text' => $existingValue['value_text'] ?? null,
248:             ];
249:         })->toArray();
250: 
251:         $offerData = [
252:             'price' => $this->offerDraft['price'] ?? null,
253:             'inventory_quantity' => $this->offerDraft['inventory_quantity'] ?? null,
254:             'is_active' => $this->offerDraft['is_active'] ?? true,
255:             'values' => $offerValues,
256:         ];
257: 
258:         // Добавляем или обновляем предложение
259:         if ($this->offerModalIndex !== null && isset($this->offers[$this->offerModalIndex])) {
260:             // Обновляем существующее предложение
261:             $this->offers[$this->offerModalIndex] = $offerData;
262:         } else {
263:             // Добавляем новое предложение
264:             $this->offers[] = $offerData;
265:         }
266: 
267:         $this->showOfferModal = false;
268:         $this->offerModalIndex = null;
269:         $this->resetOfferDraft($productPropertiesForOffers);
270:     }
271: 
272:     public function removeOffer(int $index): void
273:     {
274:         if (isset($this->offers[$index])) {
275:             unset($this->offers[$index]);
276:             $this->offers = array_values($this->offers);
277:         }
278:     }
279: 
280:     public function updateOfferPrice(int $index, ?float $price): void
281:     {
282:         if (! isset($this->offers[$index])) {
283:             return;
284:         }
285: 
286:         $this->offers[$index]['price'] = $price !== null ? max(0, $price) : null;
287:     }
288: 
289:     public function generateOffers(array $productPropertiesForOffers = []): void
290:     {
291:         $properties = collect($productPropertiesForOffers);
292: 
293:         if ($properties->isEmpty()) {
294:             return;
295:         }
296: 
297:         // Получаем все значения свойств
298:         $propertyValues = $properties->map(function ($property) {
299:             $values = collect($property['values'] ?? []);
300:             $isShared = $property['is_shared'] ?? false;
301: 
302:             return [
303:                 'property_id' => $property['id'] ?? null,
304:                 'is_shared' => $isShared,
305:                 'values' => $values->map(function ($value) {
306:                     return [
307:                         'id' => $value['id'] ?? null,
308:                         'property_value_id' => $value['id'] ?? null,
309:                     ];
310:                 })->filter(fn($v) => $v['id'] !== null)->values()->toArray(),
311:             ];
312:         })->filter(fn($p) => ! empty($p['values']))->values();
313: 
314:         if ($propertyValues->isEmpty()) {
315:             return;
316:         }
317: 
318:         // Генерируем все комбинации значений
319:         $combinations = $this->generateCombinations($propertyValues->toArray());
320: 
321:         // Получаем существующие комбинации из текущих предложений
322:         $existingCombinations = $this->getExistingCombinations();
323: 
324:         // Добавляем только новые комбинации
325:         foreach ($combinations as $combination) {
326:             $combinationKey = $this->getCombinationKey($combination);
327: 
328:             // Пропускаем, если такая комбинация уже существует
329:             if (isset($existingCombinations[$combinationKey])) {
330:                 continue;
331:             }
332: 
333:             // Создаем новое предложение
334:             $offerValues = $properties->map(function ($property) use ($combination) {
335:                 $propertyId = $property['id'] ?? null;
336:                 $isShared = $property['is_shared'] ?? false;
337: 
338:                 // Находим значение из комбинации для этого свойства
339:                 $value = collect($combination)->firstWhere('property_id', $propertyId);
340: 
341:                 return [
342:                     'property_id' => $propertyId,
343:                     'property_value_id' => $value !== null ? ($value['property_value_id'] ?? null) : null,
344:                     'value_text' => null,
345:                 ];
346:             })->toArray();
347: 
348:             $this->offers[] = [
349:                 'price' => null,
350:                 'inventory_quantity' => null,
351:                 'is_active' => true,
352:                 'values' => $offerValues,
353:             ];
354:         }
355:     }
356: 
357:     public function updatedOffers(mixed $value, string $key): void
358:     {
359:         // Обработка изменений в массиве офферов
360:         // Если изменяется price конкретного оффера, валидируем его
361:         if (str_contains($key, '.price')) {
362:             $parts = explode('.', $key);
363:             $index = (int) $parts[1];
364: 
365:             if (isset($this->offers[$index]['price'])) {
366:                 $price = $this->offers[$index]['price'];
367: 
368:                 // Нормализуем цену: убеждаемся, что она не отрицательная
369:                 if ($price !== null && $price < 0) {
370:                     $this->offers[$index]['price'] = 0;
371:                 }
372:             }
373:         }
374:     }
375: 
376:     private function resetOfferDraft(array $productPropertiesForOffers = []): void
377:     {
378:         $properties = collect($productPropertiesForOffers);
379: 
380:         $offerValues = $properties->map(function ($property) {
381:             return [
382:                 'property_id' => $property['id'] ?? null,
383:                 'property_value_id' => null,
384:                 'value_text' => null,
385:             ];
386:         })->toArray();
387: 
388:         $this->offerDraft = [
389:             'price' => null,
390:             'inventory_quantity' => null,
391:             'is_active' => true,
392:             'values' => $offerValues,
393:         ];
394:     }
395: 
396:     /**
397:      * Проверяет уникальность комбинаций propertyValues.
398:      *
399:      * @throws \Illuminate\Validation\ValidationException
400:      */
401:     private function validateUniqueCombinations(): void
402:     {
403:         $combinations = [];
404: 
405:         foreach ($this->offers as $index => $offer) {
406:             $propertyValueIds = collect($offer['values'] ?? [])
407:                 ->pluck('property_value_id')
408:                 ->filter()
409:                 ->map(fn($id) => (int) $id)
410:                 ->sort()
411:                 ->values()
412:                 ->toArray();
413: 
414:             // Если нет propertyValueIds, это предложение без свойств - пропускаем проверку
415:             if (empty($propertyValueIds)) {
416:                 continue;
417:             }
418: 
419:             $key = implode('|', $propertyValueIds);
420: 
421:             if (isset($combinations[$key])) {
422:                 $this->addError(
423:                     "offers.{$index}.values",
424:                     'Предложение с такими же значениями свойств уже существует.'
425:                 );
426: 
427:                 throw \Illuminate\Validation\ValidationException::withMessages([
428:                     "offers.{$index}.values" => ['Предложение с такими же значениями свойств уже существует.'],
429:                 ]);
430:             }
431: 
432:             $combinations[$key] = $index;
433:         }
434:     }
435: 
436:     /**
437:      * Генерирует все комбинации значений свойств.
438:      *
439:      * @param  array<int, array{property_id: int|null, is_shared: bool, values: array<int, array{id: int|null, property_value_id: int|null}>}>  $propertyValues
440:      * @return array<int, array<int, array{property_id: int|null, property_value_id: int|null}>>
441:      */
442:     private function generateCombinations(array $propertyValues): array
443:     {
444:         if (empty($propertyValues)) {
445:             return [];
446:         }
447: 
448:         $combinations = [[]];
449: 
450:         foreach ($propertyValues as $property) {
451:             $newCombinations = [];
452:             $values = $property['values'] ?? [];
453: 
454:             foreach ($combinations as $combination) {
455:                 foreach ($values as $value) {
456:                     $newCombination = $combination;
457:                     $newCombination[] = [
458:                         'property_id' => $property['property_id'],
459:                         'property_value_id' => $value['property_value_id'] ?? null,
460:                     ];
461:                     $newCombinations[] = $newCombination;
462:                 }
463:             }
464: 
465:             $combinations = $newCombinations;
466:         }
467: 
468:         return $combinations;
469:     }
470: 
471:     /**
472:      * Получает ключи существующих комбинаций из текущих предложений.
473:      *
474:      * @return array<string, bool>
475:      */
476:     private function getExistingCombinations(): array
477:     {
478:         $existing = [];
479: 
480:         foreach ($this->offers as $offer) {
481:             $values = collect($offer['values'] ?? [])
482:                 ->filter(fn($v) => ! empty($v['property_value_id']))
483:                 ->map(fn($v) => (int) $v['property_value_id'])
484:                 ->sort()
485:                 ->values()
486:                 ->toArray();
487: 
488:             if (! empty($values)) {
489:                 $key = implode('|', $values);
490:                 $existing[$key] = true;
491:             }
492:         }
493: 
494:         return $existing;
495:     }
496: 
497:     /**
498:      * Создает уникальный ключ для комбинации значений.
499:      *
500:      * @param  array<int, array{property_id?: int|null, property_value_id?: int|null}>  $combination
501:      */
502:     private function getCombinationKey(array $combination): string
503:     {
504:         $values = collect($combination)
505:             ->pluck('property_value_id')
506:             ->filter()
507:             ->map(fn($id) => (int) $id)
508:             ->sort()
509:             ->values()
510:             ->toArray();
511: 
512:         return implode('|', $values);
513:     }
514: 
515:     /**
516:      * Создает уникальный ключ для предложения из коллекции PropertyValue.
517:      *
518:      * @param  \Illuminate\Database\Eloquent\Collection<int, \Catalog\Models\PropertyValue>  $propertyValues
519:      */
520:     private function getOfferKey($propertyValues): string
521:     {
522:         $values = $propertyValues
523:             ->pluck('id')
524:             ->filter()
525:             ->map(fn($id) => (int) $id)
526:             ->sort()
527:             ->values()
528:             ->toArray();
529: 
530:         return implode('|', $values);
531:     }
532: 
533:     /**
534:      * Создает уникальный ключ для предложения из массива property_value_id.
535:      *
536:      * @param  array<int>  $propertyValueIds
537:      */
538:     private function getOfferKeyFromPropertyValueIds(array $propertyValueIds): string
539:     {
540:         $values = collect($propertyValueIds)
541:             ->filter()
542:             ->map(fn($id) => (int) $id)
543:             ->sort()
544:             ->values()
545:             ->toArray();
546: 
547:         return implode('|', $values);
548:     }
549: }
```

## Modules/Sales/app/Livewire/Forms/Product/ProductMainForm.php

SHA-256 `1ab002ad8dd1910de8a175ca9f5d09cb10b504dc55c34891977f037b24388bac`; 177 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Livewire\Forms\Product;
6: 
7: use App\Enums\Common\Moderation\ModerationStatus;
8: use Catalog\Enums\Product\Condition;
9: use Catalog\Models\Brand;
10: use Catalog\Models\Product\Product;
11: use Illuminate\Support\Facades\Auth;
12: use Illuminate\Support\Facades\DB;
13: use Illuminate\Validation\Rule;
14: use Livewire\Form;
15: use Sales\Enums\Shop\InventoryPolicy;
16: use Sales\Models\Seller\SalesModeration;
17: 
18: final class ProductMainForm extends Form
19: {
20:     public string $name = '';
21: 
22:     public ?int $brand_id = null;
23: 
24:     public Condition $condition = Condition::New;
25: 
26:     public bool $is_new = false;
27: 
28:     public bool $is_sale = false;
29: 
30:     public bool $is_discount = false;
31: 
32:     public bool $is_hit = false;
33: 
34:     public bool $is_active = true;
35: 
36:     public ?int $productId = null;
37: 
38:     public function fillFromProduct(?Product $product): void
39:     {
40:         if (! $product) {
41:             return;
42:         }
43: 
44:         $this->productId = $product->id;
45:         $this->name = $product->name;
46:         $this->brand_id = $product->brand_id;
47:         $this->condition = $product->condition;
48:         $this->is_new = $product->is_new;
49:         $this->is_sale = $product->is_sale;
50:         $this->is_discount = $product->is_discount;
51:         $this->is_hit = $product->is_hit;
52:         $this->is_active = $product->is_active;
53:     }
54: 
55:     public function getAvailableBrands()
56:     {
57:         return Brand::query()->orderBy('name')->get();
58:     }
59: 
60:     /**
61:      * Получить массив статусов товара для отображения в форме
62:      *
63:      * @return array<int, array{key: string, label: string}>
64:      */
65:     public function getStatusFields(): array
66:     {
67:         return [
68:             [
69:                 'key' => 'is_active',
70:                 'label' => 'Активен',
71:             ],
72:             [
73:                 'key' => 'is_new',
74:                 'label' => 'Новинка',
75:             ],
76:             [
77:                 'key' => 'is_sale',
78:                 'label' => 'Распродажа',
79:             ],
80:             [
81:                 'key' => 'is_discount',
82:                 'label' => 'Скидка',
83:             ],
84:             [
85:                 'key' => 'is_hit',
86:                 'label' => 'Хит продаж',
87:             ],
88:         ];
89:     }
90: 
91:     public function rules(): array
92:     {
93:         return [
94:             'name' => ['required', 'string', 'min:2', 'max:255'],
95:             'brand_id' => ['nullable', 'integer', 'exists:cat_brands,id'],
96:             'condition' => ['required', Rule::enum(Condition::class)],
97:             'is_new' => ['boolean'],
98:             'is_sale' => ['boolean'],
99:             'is_discount' => ['boolean'],
100:             'is_hit' => ['boolean'],
101:             'is_active' => ['boolean'],
102:         ];
103:     }
104: 
105:     public function messages(): array
106:     {
107:         return [
108:             'name.required' => 'Поле "Название товара" обязательно для заполнения.',
109:             'name.min' => 'Название товара должно содержать минимум :min символа.',
110:             'name.max' => 'Название товара не должно превышать :max символов.',
111:             'brand_id.exists' => 'Выберите существующий бренд.',
112:             'condition.required' => 'Поле "Состояние" обязательно для заполнения.',
113:         ];
114:     }
115: 
116:     public function validationAttributes(): array
117:     {
118:         return [
119:             'name' => 'название товара',
120:             'brand_id' => 'бренд',
121:             'condition' => 'состояние',
122:             'is_new' => 'новинка',
123:             'is_sale' => 'распродажа',
124:             'is_discount' => 'скидка',
125:             'is_hit' => 'хит продаж',
126:             'is_active' => 'активен',
127:         ];
128:     }
129: 
130:     public function save(?Product $product = null): Product
131:     {
132:         $this->validate();
133: 
134:         return DB::transaction(function () use ($product) {
135:             $product = $product ?? new Product();
136: 
137:             $product->fill([
138:                 'name' => $this->name,
139:                 'brand_id' => $this->brand_id,
140:                 'sort_order' => 0,
141:                 'condition' => $this->condition,
142:                 'inventory_policy' => InventoryPolicy::InStock,
143:                 'is_new' => $this->is_new,
144:                 'is_sale' => $this->is_sale,
145:                 'is_discount' => $this->is_discount,
146:                 'is_hit' => $this->is_hit,
147:                 'is_active' => $this->is_active,
148:             ]);
149: 
150:             if (! $product->exists) {
151:                 $user = Auth::user();
152:                 $shop = $user?->seller?->shops()->first();
153:                 if ($shop) {
154:                     $product->shop_id = $shop->id;
155:                 }
156:                 if ($user) {
157:                     $product->created_by_id = $user->id;
158:                 }
159:             }
160: 
161:             $product->save();
162: 
163:             if (! $product->exists) {
164:                 SalesModeration::create([
165:                     'seller_id' => Auth::user()->seller->id,
166:                     'moderatable_id' => $product->id,
167:                     'moderatable_type' => Product::class,
168:                     'status' => ModerationStatus::Pending,
169:                 ]);
170:             }
171: 
172:             $this->productId = $product->id;
173: 
174:             return $product;
175:         });
176:     }
177: }
```

## Modules/Sales/app/Livewire/Forms/Product/PropertiesForm.php

SHA-256 `5c851cbc9a9844058a982929e4b5493cd2a9af6574f2ce52b4ff2add84e2df6d`; 536 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Livewire\Forms\Product;
6: 
7: use Catalog\Enums\Product\PropertyControl;
8: use Catalog\Enums\Product\PropertyType;
9: use Catalog\Models\Product\Product;
10: use Catalog\Models\Property;
11: use Illuminate\Support\Facades\Auth;
12: use Illuminate\Validation\Rule;
13: use Livewire\Form;
14: 
15: final class PropertiesForm extends Form
16: {
17:     public array $properties = [];
18: 
19:     public array $propertyDraft = [];
20: 
21:     public ?int $productId = null;
22: 
23:     public bool $showPropertyModal = false;
24: 
25:     public ?int $propertyModalIndex = null;
26: 
27:     public function rules(): array
28:     {
29:         return [
30:             'properties' => ['nullable', 'array'],
31:             'properties.*.id' => ['nullable', 'integer', 'exists:cat_properties,id'],
32:             'properties.*.name' => ['required_without:properties.*.id', 'string', 'max:128'],
33:             'properties.*.type' => ['required_without:properties.*.id', Rule::enum(PropertyType::class)],
34:             'properties.*.control' => ['nullable', Rule::enum(PropertyControl::class)],
35:             'properties.*.shop_id' => ['nullable', 'integer'],
36:             'properties.*.values' => ['nullable', 'array'],
37:             'properties.*.values.*.id' => ['nullable', 'integer', 'exists:cat_property_values,id'],
38:             'properties.*.values.*.value_text' => ['nullable', 'string', 'max:255'],
39:             'properties.*.values.*.value_integer' => ['nullable', 'integer'],
40:             'properties.*.values.*.value_decimal' => ['nullable', 'numeric'],
41:             'properties.*.available_codes' => ['nullable', 'array'],
42:             'properties.*.available_codes.*' => ['nullable', 'string', 'max:255'],
43:         ];
44:     }
45: 
46:     public function fillFromProduct(?Product $product): void
47:     {
48:         if (! $product) {
49:             return;
50:         }
51: 
52:         $this->productId = $product->id;
53: 
54:         // Загружаем свойства товара только если массив пустой (чтобы не потерять добавленные пользователем свойства)
55:         if (empty($this->properties)) {
56:             $this->properties = $product->properties()
57:                 ->with('values')
58:                 ->withPivot('available_codes')
59:                 ->orderBy('cat_properties.sort_order')
60:                 ->get()
61:                 ->map(function ($property) {
62:                     $availableCodes = $this->normalizeAvailableCodes($property->pivot?->available_codes ?? []);
63:                     $control = $property->control?->value;
64:                     $type = $property->type->value;
65: 
66:                     if ($control === 'colorpicker') {
67:                         $type = 'text';
68:                     }
69: 
70:                     $values = $property->values->map(function ($value) {
71:                         return [
72:                             'id' => $value->id,
73:                             'value_text' => $value->value_text,
74:                             'value_integer' => $value->value_integer,
75:                             'value_decimal' => $value->value_decimal,
76:                             'code' => $value->code,
77:                             'label' => $value->label,
78:                         ];
79:                     });
80: 
81:                     return [
82:                         'id' => $property->id,
83:                         'name' => $property->name,
84:                         'type' => $type,
85:                         'control' => $control,
86:                         'code' => $property->code,
87:                         'values' => $values->values()->toArray(),
88:                         'shop_id' => $property->shop_id,
89:                         'is_shared' => $property->shop_id === null,
90:                         'available_codes' => $availableCodes,
91:                     ];
92:                 })->toArray();
93:         }
94:     }
95: 
96:     public function save(Product $product): void
97:     {
98:         $this->validate();
99: 
100:         // Подготавливаем данные для синхронизации
101:         $syncData = [];
102: 
103:         foreach ($this->properties as $property) {
104:             $propertyId = $property['id'] ?? null;
105: 
106:             if (! $propertyId) {
107:                 continue;
108:             }
109: 
110:             $availableCodes = $this->normalizeAvailableCodes($property['available_codes'] ?? []);
111: 
112:             $syncData[$propertyId] = [
113:                 'available_codes' => ! empty($availableCodes) ? json_encode($availableCodes) : null,
114:             ];
115:         }
116: 
117:         // Синхронизируем свойства с продуктом
118:         $product->properties()->sync($syncData);
119: 
120:         $this->productId = $product->id;
121:     }
122: 
123:     public function getProductPropertiesForOffers(): array
124:     {
125:         $properties = collect($this->properties);
126: 
127:         return $properties->map(function ($property) {
128:             $availableCodes = $this->normalizeAvailableCodes($property['available_codes'] ?? []);
129:             $values = collect($property['values'] ?? []);
130: 
131:             // Для базовых свойств (color, size) фильтруем значения по available_codes
132:             $propertyCode = $property['code'] ?? null;
133:             if (! empty($availableCodes) && in_array($propertyCode, ['color', 'size'])) {
134:                 $values = $values->filter(function ($value) use ($availableCodes) {
135:                     return isset($value['code']) && in_array($value['code'], $availableCodes);
136:                 });
137:             }
138: 
139:             return [
140:                 'id' => $property['id'] ?? null,
141:                 'name' => $property['name'] ?? '',
142:                 'type' => $property['type'] ?? null,
143:                 'control' => $property['control'] ?? null,
144:                 'values' => $values->values()->toArray(),
145:                 'shop_id' => $property['shop_id'] ?? null,
146:                 'is_shared' => $property['is_shared'] ?? false,
147:                 'available_codes' => $availableCodes,
148:             ];
149:         })->filter(fn ($property) => ! empty($property['values']))->values()->toArray();
150:     }
151: 
152:     public function openPropertyModalForCreate(): void
153:     {
154:         $this->propertyModalIndex = null;
155:         $this->resetPropertyDraft('shared');
156:         $this->showPropertyModal = true;
157:     }
158: 
159:     public function openPropertyModal(int $index): void
160:     {
161:         if (! isset($this->properties[$index])) {
162:             return;
163:         }
164: 
165:         $property = $this->properties[$index];
166:         $this->propertyModalIndex = $index;
167: 
168:         if (($property['is_shared'] ?? false) === true) {
169:             $this->resetPropertyDraft('shared');
170:             $propertyId = $property['id'] ?? null;
171:             $this->propertyDraft['id'] = $propertyId;
172:             $this->propertyDraft['selected_property_id'] = $propertyId !== null ? (int) $propertyId : null;
173:             $this->propertyDraft['name'] = $property['name'] ?? '';
174:             $this->propertyDraft['type'] = $property['type'] ?? PropertyType::Text->value;
175:             $this->propertyDraft['control'] = $property['control'] ?? null;
176:             $this->propertyDraft['values'] = $property['values'] ?? [];
177:             $this->propertyDraft['available_codes'] = $this->normalizeAvailableCodes($property['available_codes'] ?? []);
178:             $this->propertyDraft['is_shared'] = true;
179:         } else {
180:             $this->resetPropertyDraft('custom');
181:             $this->propertyDraft['id'] = $property['id'] ?? null;
182:             $this->propertyDraft['shop_id'] = $property['shop_id'] ?? null;
183:             $this->propertyDraft['name'] = $property['name'] ?? '';
184:             $this->propertyDraft['type'] = $property['type'] ?? PropertyType::Text->value;
185:             $this->propertyDraft['control'] = $property['control'] ?? null;
186:             $this->propertyDraft['values'] = $property['values'] ?? [];
187:             $this->propertyDraft['is_shared'] = false;
188:         }
189: 
190:         $this->showPropertyModal = true;
191:     }
192: 
193:     public function removeProperty(int $index): void
194:     {
195:         if (isset($this->properties[$index])) {
196:             unset($this->properties[$index]);
197:             $this->properties = array_values($this->properties);
198:         }
199:     }
200: 
201:     public function getProductPropertyName(?int $propertyId): string
202:     {
203:         if (! $propertyId) {
204:             return '';
205:         }
206: 
207:         $property = collect($this->properties)->firstWhere('id', $propertyId);
208: 
209:         return $property ? ($property['name'] ?? 'Свойство') : '';
210:     }
211: 
212:     public function getPropertyValues(?int $propertyId): array
213:     {
214:         if (! $propertyId) {
215:             return [];
216:         }
217: 
218:         $property = collect($this->properties)->firstWhere('id', $propertyId);
219: 
220:         return $property ? ($property['values'] ?? []) : [];
221:     }
222: 
223:     public function getPropertyValueLabel(array $value): string
224:     {
225:         if (isset($value['value_text']) && $value['value_text']) {
226:             return $value['value_text'];
227:         }
228:         if (isset($value['value_integer']) && $value['value_integer'] !== null) {
229:             return (string) $value['value_integer'];
230:         }
231:         if (isset($value['value_decimal']) && $value['value_decimal'] !== null) {
232:             return (string) $value['value_decimal'];
233:         }
234: 
235:         return '';
236:     }
237: 
238:     /**
239:      * @return array<int, string>
240:      */
241:     public function normalizeAvailableCodes(mixed $availableCodes): array
242:     {
243:         if (is_string($availableCodes)) {
244:             $decoded = json_decode($availableCodes, true);
245: 
246:             return is_array($decoded) ? array_values(array_filter($decoded, fn ($code) => $code !== null && $code !== '')) : [];
247:         }
248: 
249:         if (is_array($availableCodes)) {
250:             return array_values(array_filter($availableCodes, fn ($code) => $code !== null && $code !== ''));
251:         }
252: 
253:         return [];
254:     }
255: 
256:     public function setPropertyDraftMode(string $mode): void
257:     {
258:         $this->resetPropertyDraft($mode === 'custom' ? 'custom' : 'shared');
259:     }
260: 
261:     public function updatedPropertyDraftSelectedPropertyId($propertyId): void
262:     {
263:         if (($this->propertyDraft['mode'] ?? 'shared') !== 'shared') {
264:             return;
265:         }
266: 
267:         $this->propertyDraft['available_codes'] = [];
268: 
269:         if (! $propertyId) {
270:             $this->propertyDraft['values'] = [];
271:             $this->propertyDraft['available_codes'] = [];
272: 
273:             return;
274:         }
275: 
276:         $property = Property::with('values')
277:             ->whereNull('shop_id')
278:             ->find((int) $propertyId);
279: 
280:         $this->propertyDraft['selected_property_id'] = $property?->id;
281: 
282:         if (! $property) {
283:             $this->propertyDraft['values'] = [];
284:             $this->propertyDraft['available_codes'] = [];
285: 
286:             return;
287:         }
288: 
289:         $values = $this->mapPropertyValues($property);
290: 
291:         $this->propertyDraft = [
292:             'mode' => 'shared',
293:             'id' => $property->id,
294:             'selected_property_id' => $property->id,
295:             'shop_id' => $property->shop_id,
296:             'name' => $property->name,
297:             'type' => $property->type->value,
298:             'control' => $property->control?->value,
299:             'values' => array_values($values),
300:             'is_shared' => true,
301:             // При выборе свойства список разрешенных кодов должен быть пустым по умолчанию.
302:             'available_codes' => [],
303:         ];
304:     }
305: 
306:     public function updatedPropertyDraftControl(?string $control): void
307:     {
308:         if (($this->propertyDraft['mode'] ?? 'shared') !== 'custom') {
309:             return;
310:         }
311: 
312:         if ($control === PropertyControl::Colorpicker->value) {
313:             $this->propertyDraft['type'] = PropertyType::Text->value;
314:         }
315:     }
316: 
317:     public function addDraftValue(): void
318:     {
319:         if (($this->propertyDraft['mode'] ?? 'shared') !== 'custom') {
320:             return;
321:         }
322: 
323:         $this->propertyDraft['values'][] = [
324:             'value_text' => null,
325:             'value_integer' => null,
326:             'value_decimal' => null,
327:         ];
328:     }
329: 
330:     public function removeDraftValue(int $valueIndex): void
331:     {
332:         if (($this->propertyDraft['mode'] ?? 'shared') !== 'custom') {
333:             return;
334:         }
335: 
336:         if (! isset($this->propertyDraft['values'][$valueIndex])) {
337:             return;
338:         }
339: 
340:         unset($this->propertyDraft['values'][$valueIndex]);
341:         $this->propertyDraft['values'] = array_values($this->propertyDraft['values']);
342:     }
343: 
344:     public function savePropertyFromModal(): void
345:     {
346:         $mode = $this->propertyDraft['mode'] ?? 'shared';
347:         $propertyData = [];
348: 
349:         if ($mode === 'shared') {
350:             // Используем существующее shared свойство
351:             $propertyId = $this->propertyDraft['selected_property_id'] ?? null;
352: 
353:             if (! $propertyId) {
354:                 return;
355:             }
356: 
357:             $property = Property::with('values')
358:                 ->whereNull('shop_id')
359:                 ->find((int) $propertyId);
360: 
361:             if (! $property) {
362:                 return;
363:             }
364: 
365:             $availableCodes = $this->normalizeAvailableCodes($this->propertyDraft['available_codes'] ?? []);
366:             $values = $this->mapPropertyValues($property);
367: 
368:             if (! empty($availableCodes)) {
369:                 $values = $this->filterValuesByCodes($values, $availableCodes);
370:             }
371: 
372:             $propertyData = [
373:                 'id' => $property->id,
374:                 'name' => $property->name,
375:                 'type' => $property->type->value,
376:                 'control' => $property->control?->value,
377:                 'values' => array_values($values),
378:                 'shop_id' => null,
379:                 'is_shared' => true,
380:                 'available_codes' => $availableCodes,
381:             ];
382:         } else {
383:             // Создаем или обновляем custom свойство магазина
384:             $shopId = $this->getCurrentShopId();
385: 
386:             if (! $shopId) {
387:                 return;
388:             }
389: 
390:             $propertyId = $this->propertyDraft['id'] ?? null;
391:             $name = $this->propertyDraft['name'] ?? '';
392:             $type = PropertyType::tryFrom($this->propertyDraft['type'] ?? PropertyType::Text->value) ?? PropertyType::Text;
393:             $control = PropertyControl::tryFrom($this->propertyDraft['control'] ?? PropertyControl::Input->value) ?? PropertyControl::Input;
394: 
395:             if (empty($name)) {
396:                 return;
397:             }
398: 
399:             if ($propertyId) {
400:                 // Обновляем существующее свойство
401:                 $property = Property::where('shop_id', $shopId)->find($propertyId);
402: 
403:                 if ($property) {
404:                     $property->update([
405:                         'name' => $name,
406:                         'type' => $type,
407:                         'control' => $control,
408:                     ]);
409: 
410:                     // Обновляем значения
411:                     $this->savePropertyValues($property, $this->propertyDraft['values'] ?? []);
412:                 }
413:             } else {
414:                 // Создаем новое свойство
415:                 $property = Property::create([
416:                     'shop_id' => $shopId,
417:                     'name' => $name,
418:                     'type' => $type,
419:                     'control' => $control,
420:                 ]);
421: 
422:                 // Сохраняем значения
423:                 $this->savePropertyValues($property, $this->propertyDraft['values'] ?? []);
424: 
425:                 $property->load('values');
426:             }
427: 
428:             $values = $this->mapPropertyValues($property);
429: 
430:             $propertyData = [
431:                 'id' => $property->id,
432:                 'name' => $property->name,
433:                 'type' => $property->type->value,
434:                 'control' => $property->control?->value,
435:                 'values' => array_values($values),
436:                 'shop_id' => $property->shop_id,
437:                 'is_shared' => false,
438:                 'available_codes' => [],
439:             ];
440:         }
441: 
442:         // Добавляем или обновляем свойство в массиве
443:         if ($this->propertyModalIndex !== null && isset($this->properties[$this->propertyModalIndex])) {
444:             // Обновляем существующее свойство
445:             $this->properties[$this->propertyModalIndex] = $propertyData;
446:         } else {
447:             // Добавляем новое свойство
448:             $this->properties[] = $propertyData;
449:         }
450: 
451:         $this->showPropertyModal = false;
452:         $this->propertyModalIndex = null;
453:         $this->resetPropertyDraft();
454:     }
455: 
456:     /**
457:      * @return array<int, array{id: int, name: string}>
458:      */
459:     public function getAvailableSharedProperties(): array
460:     {
461:         return Property::whereNull('shop_id')
462:             ->orderBy('name')
463:             ->get()
464:             ->map(fn ($property) => [
465:                 'id' => $property->id,
466:                 'name' => $property->name,
467:             ])
468:             ->toArray();
469:     }
470: 
471:     private function resetPropertyDraft(?string $mode = null): void
472:     {
473:         $mode = $mode ?? 'shared';
474: 
475:         $this->propertyDraft = [
476:             'mode' => $mode,
477:             'id' => null,
478:             'selected_property_id' => null,
479:             'shop_id' => $this->getCurrentShopId(),
480:             'name' => '',
481:             'type' => PropertyType::Text->value,
482:             'control' => PropertyControl::Input->value,
483:             'values' => [],
484:             'is_shared' => $mode === 'shared',
485:             'available_codes' => [],
486:         ];
487:     }
488: 
489:     private function savePropertyValues(Property $property, array $values): void
490:     {
491:         $property->values()->delete();
492: 
493:         foreach ($values as $valueData) {
494:             $property->values()->create([
495:                 'value_text' => $valueData['value_text'] ?? null,
496:                 'value_integer' => $valueData['value_integer'] ?? null,
497:                 'value_decimal' => $valueData['value_decimal'] ?? null,
498:                 'code' => $valueData['code'] ?? null,
499:                 'label' => $valueData['label'] ?? null,
500:             ]);
501:         }
502:     }
503: 
504:     private function mapPropertyValues(Property $property): array
505:     {
506:         return $property->values->map(function ($value) {
507:             return [
508:                 'id' => $value->id,
509:                 'value_text' => $value->value_text,
510:                 'value_integer' => $value->value_integer,
511:                 'value_decimal' => $value->value_decimal,
512:                 'code' => $value->code,
513:                 'label' => $value->label,
514:             ];
515:         })->toArray();
516:     }
517: 
518:     private function filterValuesByCodes(array $values, array $codes): array
519:     {
520:         $allowed = collect($codes)->filter()->values();
521: 
522:         if ($allowed->isEmpty()) {
523:             return $values;
524:         }
525: 
526:         return collect($values)
527:             ->filter(fn ($value) => isset($value['code']) && $value['code'] !== null && $allowed->contains($value['code']))
528:             ->values()
529:             ->toArray();
530:     }
531: 
532:     private function getCurrentShopId(): ?int
533:     {
534:         return Auth::user()?->seller?->shops()->first()?->id;
535:     }
536: }
```

## Modules/Sales/app/Livewire/Forms/Product/SpecificationForm.php

SHA-256 `969198d70f862c0e5d3722136fcf3d75cf5d46565d6a8c34b5a9e8e027f6e495`; 186 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Livewire\Forms\Product;
6: 
7: use Catalog\Models\Product\Product;
8: use Catalog\Models\Product\ProductSpecificationGroup;
9: use Catalog\Models\Product\ProductSpecificationOption;
10: use Catalog\Models\Product\SpecificationGroup;
11: use Illuminate\Support\Facades\Auth;
12: use Livewire\Form;
13: 
14: final class SpecificationForm extends Form
15: {
16:     public array $groups = [];
17: 
18:     public ?int $productId = null;
19: 
20:     public function rules(): array
21:     {
22:         return [
23:             'groups' => ['nullable', 'array'],
24:             'groups.*.id' => ['nullable', 'integer', 'exists:cat_product_specification_groups,id'],
25:             'groups.*.group_id' => ['required', 'integer', 'exists:cat_specification_groups,id'],
26:             'groups.*.content' => ['nullable', 'array'],
27:             'groups.*.sort_order' => ['nullable', 'integer'],
28:             'groups.*.options' => ['nullable', 'array'],
29:             'groups.*.options.*.id' => ['nullable', 'integer', 'exists:cat_product_specification_options,id'],
30:             'groups.*.options.*.name' => ['nullable', 'string', 'max:255'],
31:             'groups.*.options.*.value' => ['required_with:groups.*.options.*.name', 'string', 'max:255'],
32:             'groups.*.options.*.sort_order' => ['nullable', 'integer'],
33:         ];
34:     }
35: 
36:     public function fillFromProduct(?Product $product): void
37:     {
38:         if (! $product) {
39:             return;
40:         }
41: 
42:         $this->productId = $product->id;
43: 
44:         if (empty($this->groups)) {
45:             $this->groups = ProductSpecificationGroup::where('product_id', $product->id)
46:                 ->with('options')
47:                 ->orderBy('sort_order')
48:                 ->get()
49:                 ->map(function (ProductSpecificationGroup $group) {
50:                     return [
51:                         'id' => $group->id,
52:                         'group_id' => $group->group_id,
53:                         'content' => $group->content ?? [],
54:                         'sort_order' => $group->sort_order,
55:                         'options' => $group->options()
56:                             ->orderBy('sort_order')
57:                             ->get()
58:                             ->map(function (ProductSpecificationOption $option) {
59:                                 $content = $option->content ?? [];
60: 
61:                                 return [
62:                                     'id' => $option->id,
63:                                     'name' => $content['name'] ?? null,
64:                                     'value' => $content['value'] ?? '',
65:                                     'sort_order' => $option->sort_order,
66:                                 ];
67:                             })
68:                             ->values()
69:                             ->toArray(),
70:                     ];
71:                 })
72:                 ->toArray();
73:         }
74:     }
75: 
76:     public function save(Product $product): void
77:     {
78:         $this->validate();
79: 
80:         $existingGroupIds = [];
81: 
82:         foreach ($this->groups as $groupData) {
83:             $group = null;
84: 
85:             if (isset($groupData['id'])) {
86:                 $group = ProductSpecificationGroup::where('product_id', $product->id)
87:                     ->find($groupData['id']);
88:             }
89: 
90:             // Content теперь всегда приходит как массив (JSON) из Flux Editor
91:             $content = $groupData['content'] ?? [];
92:             if (! is_array($content)) {
93:                 $content = [];
94:             }
95: 
96:             if ($group) {
97:                 $group->update([
98:                     'group_id' => $groupData['group_id'],
99:                     'content' => $content,
100:                     'sort_order' => $groupData['sort_order'] ?? 500,
101:                 ]);
102:             } else {
103:                 $group = ProductSpecificationGroup::create([
104:                     'product_id' => $product->id,
105:                     'group_id' => $groupData['group_id'],
106:                     'content' => $content,
107:                     'sort_order' => $groupData['sort_order'] ?? 500,
108:                 ]);
109:             }
110: 
111:             $existingGroupIds[] = $group->id;
112: 
113:             // Сохраняем опции
114:             $existingOptionIds = [];
115: 
116:             foreach ($groupData['options'] ?? [] as $optionData) {
117:                 $option = null;
118: 
119:                 if (isset($optionData['id'])) {
120:                     $option = ProductSpecificationOption::where('product_id', $product->id)
121:                         ->where('group_id', $group->id)
122:                         ->find($optionData['id']);
123:                 }
124: 
125:                 // Формируем content как JSON с name и value
126:                 $content = [
127:                     'name' => $optionData['name'] ?? null,
128:                     'value' => $optionData['value'] ?? '',
129:                 ];
130: 
131:                 if ($option) {
132:                     $option->update([
133:                         'content' => $content,
134:                         'sort_order' => $optionData['sort_order'] ?? 500,
135:                     ]);
136:                 } else {
137:                     $option = ProductSpecificationOption::create([
138:                         'product_id' => $product->id,
139:                         'group_id' => $group->id,
140:                         'content' => $content,
141:                         'sort_order' => $optionData['sort_order'] ?? 500,
142:                     ]);
143:                 }
144: 
145:                 $existingOptionIds[] = $option->id;
146:             }
147: 
148:             // Удаляем опции, которые были удалены
149:             ProductSpecificationOption::where('product_id', $product->id)
150:                 ->where('group_id', $group->id)
151:                 ->whereNotIn('id', $existingOptionIds)
152:                 ->delete();
153:         }
154: 
155:         // Удаляем группы, которые были удалены
156:         ProductSpecificationGroup::where('product_id', $product->id)
157:             ->whereNotIn('id', $existingGroupIds)
158:             ->delete();
159: 
160:         $this->productId = $product->id;
161:     }
162: 
163:     /**
164:      * @return array<int, array{id: int, name: string}>
165:      */
166:     public function getAvailableSpecificationGroups(): array
167:     {
168:         return SpecificationGroup::orderBy('name')
169:             ->get()
170:             ->map(fn(SpecificationGroup $group) => [
171:                 'id' => $group->id,
172:                 'name' => $group->name,
173:             ])
174:             ->toArray();
175:     }
176: 
177:     public function createSpecificationGroup(string $name): int
178:     {
179:         $group = SpecificationGroup::create([
180:             'name' => $name,
181:             'created_by_id' => Auth::id(),
182:         ]);
183: 
184:         return $group->id;
185:     }
186: }
```

## Modules/Sales/app/Livewire/Pages/Catalog/Products/ProductPage.php

SHA-256 `bd82ad0a5c8f0d11ac3fb201a9c40feebe4820284a805d2dfb20443ecdb9b773`; 89 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Livewire\Pages\Catalog\Products;
6: 
7: use App\Enums\Common\Moderation\ModerationStatus;
8: use Catalog\Enums\Product\Status as ProductStatus;
9: use Catalog\Models\Product\Product;
10: use Illuminate\Support\Facades\Auth;
11: use Illuminate\Support\Facades\DB;
12: use Livewire\Attributes\Layout;
13: use Livewire\Attributes\Url;
14: use Livewire\Component;
15: use Sales\Policies\Shop\ShopProductsPermission;
16: 
17: #[Layout('sales::components.layouts.master')]
18: final class ProductPage extends Component
19: {
20:     public ?Product $product = null;
21: 
22:     public bool $editable = true;
23: 
24:     #[Url(as: 'tab')]
25:     public string $activeTab = 'main';
26: 
27:     public function mount(?Product $product = null): void
28:     {
29:         $this->authorize(ShopProductsPermission::view->value);
30: 
31:         if (! Auth::user()?->seller) {
32:             abort(403, 'Доступ запрещен. Требуется аккаунт продавца.');
33:         }
34: 
35:         $this->product = $product;
36: 
37:         // Если товар не создан, показываем только основную вкладку
38:         if (! $this->product) {
39:             $this->activeTab = 'main';
40:         }
41:     }
42: 
43:     public function setActiveTab(string $tab): void
44:     {
45:         $this->activeTab = $tab;
46:     }
47: 
48:     public function submitForModeration(): void
49:     {
50:         if (! $this->product) {
51:             return;
52:         }
53: 
54:         // Проверяем, что товар в статусе draft
55:         if ($this->product->status !== ProductStatus::Draft) {
56:             $this->js("window.Flux?.toast('Товар можно отправить на модерацию только из статуса \"Черновик\".', {variant: 'error'})");
57: 
58:             return;
59:         }
60: 
61:         $user = Auth::user();
62:         if (! $user?->seller) {
63:             abort(403, 'Доступ запрещен. Требуется аккаунт продавца.');
64:         }
65: 
66:         DB::transaction(function () use ($user) {
67:             // Обновляем статус товара
68:             $this->product->update([
69:                 'status' => ProductStatus::Moderation,
70:             ]);
71: 
72:             // Создаем запись о модерации
73:             $this->product->moderations()->create([
74:                 'seller_id' => $user->seller->id,
75:                 'status' => ModerationStatus::Pending,
76:             ]);
77:         });
78: 
79:         // Обновляем продукт из базы
80:         $this->product->refresh();
81: 
82:         $this->js("window.Flux?.toast('Товар успешно отправлен на модерацию.', {variant: 'success'})");
83:     }
84: 
85:     public function render()
86:     {
87:         return view('sales::livewire.components.product-form');
88:     }
89: }
```

## Modules/Sales/app/Repositories/Collections/CollectionRepository.php

SHA-256 `96f24bc3293b4374de9f922912fb36cd2beb15b0081f8eabfc1a539d7069e63d`; 29 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Repositories\Collections;
6: 
7: use App\Enums\Common\Moderation\ModerationStatus;
8: use Sales\Models\Seller\SalesModeration;
9: use Catalog\Models\Collection\Collection;
10: 
11: final class CollectionRepository
12: {
13:     public static function getPendingModeration(Collection $collection): ?SalesModeration
14:     {
15:         return SalesModeration::where('moderatable_type', Collection::class)
16:             ->where('moderatable_id', $collection->id)
17:             ->where('status', ModerationStatus::Pending)
18:             ->orderBy('created_at', 'desc')
19:             ->first();
20:     }
21: 
22:     public static function getLastModeration(Collection $collection): ?SalesModeration
23:     {
24:         return SalesModeration::where('moderatable_type', Collection::class)
25:             ->where('moderatable_id', $collection->id)
26:             ->orderBy('created_at', 'desc')
27:             ->first();
28:     }
29: }
```

## Modules/Sales/app/Repositories/Products/ProductOffersRepository.php

SHA-256 `276039373b3840deb206e1de7960a571b4b88de316be51902995fd527d02ae8b`; 128 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Repositories\Products;
6: 
7: use Catalog\Models\Product\Product;
8: use Catalog\Models\Product\ProductOffer;
9: use Catalog\Models\Property;
10: use Catalog\Models\PropertyValue;
11: use Illuminate\Support\Collection;
12: 
13: final class ProductOffersRepository
14: {
15:     public static function generateOffers(Product $product): void
16:     {
17:         // Получаем свойства товара с available_codes
18:         $properties = $product->properties()
19:             ->withPivot('available_codes')
20:             ->with('values')
21:             ->get();
22: 
23:         if ($properties->isEmpty()) {
24:             // Если нет свойств, создаем одно предложение без значений
25:             ProductOffer::create([
26:                 'product_id' => $product->id,
27:                 'price' => $product->price ?? 0,
28:                 'inventory_quantity' => fake()->numberBetween(0, 100),
29:                 'is_active' => true,
30:             ]);
31: 
32:             return;
33:         }
34: 
35:         // Фильтруем свойства: для базовых свойств используем только available_codes
36:         $filteredProperties = $properties->map(function ($property) {
37:             $availableCodes = $property->pivot->available_codes ?? [];
38:             
39:             // Нормализуем available_codes - может быть строкой, массивом или null
40:             if (is_string($availableCodes)) {
41:                 $availableCodes = json_decode($availableCodes, true) ?? [];
42:             }
43:             if (! is_array($availableCodes)) {
44:                 $availableCodes = [];
45:             }
46: 
47:             // Если это базовое свойство (color, size) и есть available_codes, фильтруем значения
48:             if (! empty($availableCodes) && in_array($property->code, ['color', 'size'])) {
49:                 $filteredValues = $property->values->filter(function ($value) use ($availableCodes) {
50:                     return in_array($value->code, $availableCodes);
51:                 });
52: 
53:                 return [
54:                     'property' => $property,
55:                     'values' => $filteredValues,
56:                 ];
57:             }
58: 
59:             // Для небазовых свойств используем все значения
60:             return [
61:                 'property' => $property,
62:                 'values' => $property->values,
63:             ];
64:         })->filter(fn ($item) => $item['values']->isNotEmpty());
65: 
66:         if ($filteredProperties->isEmpty()) {
67:             // Если после фильтрации нет значений, создаем одно предложение
68:             ProductOffer::create([
69:                 'product_id' => $product->id,
70:                 'price' => $product->price ?? 0,
71:                 'inventory_quantity' => fake()->numberBetween(0, 100),
72:                 'is_active' => true,
73:             ]);
74: 
75:             return;
76:         }
77: 
78:         // Генерируем все комбинации значений свойств
79:         $combinations = self::generateCombinations($filteredProperties);
80: 
81:         // Создаем предложения для каждой комбинации
82:         foreach ($combinations as $combination) {
83:             $offer = ProductOffer::create([
84:                 'product_id' => $product->id,
85:                 'price' => $product->price ?? 0,
86:                 'inventory_quantity' => fake()->numberBetween(0, 100),
87:                 'is_active' => true,
88:             ]);
89: 
90:             // Привязываем значения свойств к предложению
91:             foreach ($combination as $propertyValue) {
92:                 $offer->propertyValues()->attach($propertyValue->id);
93:             }
94:         }
95:     }
96: 
97:     /**
98:      * Генерирует все комбинации значений свойств.
99:      *
100:      * @param  Collection<int, array{property: Property, values: Collection<int, PropertyValue>}>  $filteredProperties
101:      * @return array<int, array<int, PropertyValue>>
102:      */
103:     private static function generateCombinations(Collection $filteredProperties): array
104:     {
105:         if ($filteredProperties->isEmpty()) {
106:             return [];
107:         }
108: 
109:         $combinations = [[]];
110: 
111:         foreach ($filteredProperties as $item) {
112:             $newCombinations = [];
113:             $values = $item['values'];
114: 
115:             foreach ($combinations as $combination) {
116:                 foreach ($values as $value) {
117:                     $newCombination = $combination;
118:                     $newCombination[] = $value;
119:                     $newCombinations[] = $newCombination;
120:                 }
121:             }
122: 
123:             $combinations = $newCombinations;
124:         }
125: 
126:         return $combinations;
127:     }
128: }
```

## Modules/Sales/app/Repositories/Products/ProductRepository.php

SHA-256 `a68d95452b2ab2ac0e66528fb2aa5df9cf9b602a476c3a029108c0a840719ad7`; 29 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Repositories\Products;
6: 
7: use App\Enums\Common\Moderation\ModerationStatus;
8: use Catalog\Models\Product\Product;
9: use Sales\Models\Seller\SalesModeration;
10: 
11: final class ProductRepository
12: {
13:     public static function getPendingModeration(Product $product): ?SalesModeration
14:     {
15:         return SalesModeration::where('moderatable_type', Product::class)
16:             ->where('moderatable_id', $product->id)
17:             ->where('status', ModerationStatus::Pending)
18:             ->orderBy('created_at', 'desc')
19:             ->first();
20:     }
21: 
22:     public static function getLastModeration(Product $product): ?SalesModeration
23:     {
24:         return SalesModeration::where('moderatable_type', Product::class)
25:             ->where('moderatable_id', $product->id)
26:             ->orderBy('created_at', 'desc')
27:             ->first();
28:     }
29: }
```

## Modules/Sales/app/Repositories/Sellers/RegisterRepository.php

SHA-256 `7fdb3692d2b0d6b0cd4163e866fbbf8e502af7de66cde10641102cbafb82c3a6`; 282 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Repositories\Sellers;
6: 
7: use App\Enums\Common\Moderation\ModerationStatus;
8: use App\Enums\User\Role;
9: use App\Models\User;
10: use App\Support\FileEditorHelper;
11: use App\Utils\FormHelper;
12: use Illuminate\Auth\Events\Registered;
13: use Illuminate\Support\Facades\DB;
14: use Illuminate\Support\Facades\Hash;
15: use MatanYadaev\EloquentSpatial\Objects\Point;
16: use Sales\Dto\PartnerRegisterData;
17: use Sales\Events\AfterSellerRegistred;
18: use Sales\Models\Seller\SalesModeration;
19: use Sales\Models\Seller\Seller;
20: use Sales\Models\Seller\Team;
21: use Sales\Models\Shop\Shop;
22: 
23: final class RegisterRepository
24: {
25:     public static function store(PartnerRegisterData $data): User
26:     {
27:         return DB::transaction(function () use ($data) {
28:             $phoneNumber = FormHelper::cleanPhoneNumber($data->user->phone_number);
29:             $isUpdate = $data->company->seller_id !== null;
30: 
31:             if ($isUpdate) {
32:                 // Обновление существующих данных
33:                 $seller = Seller::findOrFail($data->company->seller_id);
34:                 $company = $seller->company;
35:                 $address = $company->address;
36:                 $user = $address->createdBy;
37: 
38:                 self::updateUser($user, $data, $phoneNumber);
39:                 self::updateSeller($seller, $data);
40:                 self::syncDocuments($seller, $data->company->documents);
41:                 self::syncBusinessTypes($seller, $data->company->businessTypes);
42:                 self::syncWarehouses($seller, $data->company->warehouses);
43:                 $company = self::updateCompany($company, $data, $phoneNumber);
44:                 $logoFiles = $data->company->logo !== null ? [$data->company->logo] : [];
45:                 self::syncLogo($company, $logoFiles);
46:                 self::updateAddress($address, $data, $user->id);
47:                 self::updateShop($seller, $data);
48:             } else {
49:                 // Регистрация нового продавца
50:                 $user = self::createUser($data, $phoneNumber);
51:                 $seller = self::createSeller($data);
52: 
53:                 // Добавляем пользователя в команду продавца
54:                 self::addUserToTeam($user, $seller, $data);
55:                 self::syncDocuments($seller, $data->company->documents);
56:                 self::syncBusinessTypes($seller, $data->company->businessTypes);
57:                 self::syncWarehouses($seller, $data->company->warehouses);
58:                 $company = self::createCompany($seller, $data, $phoneNumber);
59:                 $logoFiles = $data->company->logo !== null ? [$data->company->logo] : [];
60:                 self::syncLogo($company, $logoFiles);
61:                 self::createAddress($company, $data, $user->id);
62:                 self::createShop($seller, $data);
63: 
64:                 // Генерируем событие после регистрации пользователя
65:                 event(new Registered($user));
66: 
67:                 // Генерируем событие после регистрации продавца
68:                 event(new AfterSellerRegistred($user, $seller));
69:             }
70: 
71:             SalesModeration::create([
72:                 'seller_id' => $seller->id,
73:                 'moderatable_type' => Seller::class,
74:                 'moderatable_id' => $seller->id,
75:                 'status' => ModerationStatus::Pending,
76:             ]);
77: 
78:             return $user;
79:         });
80:     }
81: 
82:     private static function createUser(PartnerRegisterData $data, string $phoneNumber): User
83:     {
84:         $user = User::create([
85:             'first_name' => $data->user->first_name,
86:             'middle_name' => $data->user->middle_name,
87:             'last_name' => $data->user->last_name,
88:             'email' => $data->user->email,
89:             'phone_number' => $phoneNumber,
90:             'password' => Hash::make($data->user->password),
91:         ]);
92: 
93:         $user->assignRole(role: Role::SellerAdmin);
94: 
95:         // Чат будет создан автоматически через UserObserver::handleRegistered()
96:         // при срабатывании события Registered (строка 54)
97: 
98:         return $user;
99:     }
100: 
101:     private static function updateUser(User $user, PartnerRegisterData $data, string $phoneNumber): void
102:     {
103:         $user->update([
104:             'first_name' => $data->user->first_name,
105:             'middle_name' => $data->user->middle_name,
106:             'last_name' => $data->user->last_name,
107:             'email' => $data->user->email,
108:             'phone_number' => $phoneNumber,
109:         ]);
110:     }
111: 
112:     private static function createSeller(PartnerRegisterData $data): Seller
113:     {
114:         return Seller::create([
115:             'name' => $data->company->shop_name,
116:             'description' => $data->company->biography,
117:             'is_active' => true,
118:         ]);
119:     }
120: 
121:     private static function updateSeller(Seller $seller, PartnerRegisterData $data): void
122:     {
123:         $seller->update([
124:             'name' => $data->company->shop_name,
125:             'description' => $data->company->biography,
126:         ]);
127:     }
128: 
129:     private static function syncDocuments(Seller $seller, array $documents): void
130:     {
131:         // Используем FileEditorHelper для обработки файлов на дефолтном диске
132:         FileEditorHelper::processFiles(
133:             model: $seller,
134:             collectionName: 'documents',
135:             files: $documents,
136:             isPrivateDisk: false
137:         );
138:     }
139: 
140:     private static function syncBusinessTypes(Seller $seller, array $businessTypeIds): void
141:     {
142:         $seller->businessTypes()->delete();
143: 
144:         foreach ($businessTypeIds as $businessTypeId) {
145:             $seller->businessTypes()->create([
146:                 'type_id' => $businessTypeId,
147:             ]);
148:         }
149:     }
150: 
151:     private static function syncWarehouses(Seller $seller, array $warehouses): void
152:     {
153:         $seller->warehouses()->delete();
154: 
155:         foreach ($warehouses as $warehouse) {
156:             // Обрабатываем как объект WarehouseData или массив (из-за Laravel Data сериализации)
157:             if (is_array($warehouse)) {
158:                 $seller->warehouses()->create([
159:                     'name' => $warehouse['name'] ?? '',
160:                     'city_id' => $warehouse['city_id'] ?? 0,
161:                 ]);
162:             } else {
163:                 $seller->warehouses()->create([
164:                     'name' => $warehouse->name,
165:                     'city_id' => $warehouse->city_id,
166:                 ]);
167:             }
168:         }
169:     }
170: 
171:     private static function createCompany(Seller $seller, PartnerRegisterData $data, string $phoneNumber)
172:     {
173:         return $seller->company()->create([
174:             'name' => $data->company->legalName,
175:             'legal_status' => $data->company->legal_status,
176:             'legal_name' => $data->company->legalName,
177:             'email' => $data->user->email,
178:             'phone_number' => $phoneNumber,
179:         ]);
180:     }
181: 
182:     private static function updateCompany($company, PartnerRegisterData $data, string $phoneNumber)
183:     {
184:         $company->update([
185:             'name' => $data->company->legalName,
186:             'legal_status' => $data->company->legal_status,
187:             'legal_name' => $data->company->legalName,
188:             'email' => $data->user->email,
189:             'phone_number' => $phoneNumber,
190:         ]);
191: 
192:         return $company;
193:     }
194: 
195:     private static function syncLogo($company, array $logoFiles): void
196:     {
197:         // Используем FileEditorHelper для обработки файлов на дефолтном диске
198:         FileEditorHelper::processFiles(
199:             model: $company,
200:             collectionName: 'logo',
201:             files: $logoFiles,
202:             isPrivateDisk: false
203:         );
204:     }
205: 
206:     private static function createAddress($company, PartnerRegisterData $data, int $userId): void
207:     {
208:         $address = $company->address()->create([
209:             'created_by_id' => $userId,
210:             'city_id' => $data->company->address->city_id,
211:             'street' => $data->company->address->street?->street_with_type ?? $data->company->address->house?->street_with_type,
212:             'house' => $data->company->address->house?->getDisplayName() ?? $data->company->address->house?->value,
213:             'apartment' => $data->company->address->apartment,
214:             'postal_code' => $data->company->address->house?->postal_code,
215:             'house_fias_id' => $data->company->address->house?->getFiasId(),
216:             'house_kladr_id' => $data->company->address->house?->kladr_id,
217:             'geo_point' => new Point((float) $data->company->address->house?->geo_lat ?? 0, (float) $data->company->address->house?->geo_lon ?? 0),
218:         ]);
219: 
220:         self::syncKladrAddress($address, $data);
221:     }
222: 
223:     private static function updateAddress($address, PartnerRegisterData $data, int $userId): void
224:     {
225:         $address->update([
226:             'city_id' => $data->company->address->city_id,
227:             'street' => $data->company->address->street?->street_with_type ?? $data->company->address->house?->street_with_type,
228:             'house' => $data->company->address->house?->getDisplayName() ?? $data->company->address->house?->value,
229:             'apartment' => $data->company->address->apartment,
230:             'postal_code' => $data->company->address->house?->postal_code,
231:             'house_fias_id' => $data->company->address->house?->getFiasId(),
232:             'house_kladr_id' => $data->company->address->house?->kladr_id,
233:             'geo_point' => new Point((float) $data->company->address->house?->geo_lat ?? 0, (float) $data->company->address->house?->geo_lon ?? 0),
234:         ]);
235: 
236:         self::syncKladrAddress($address, $data);
237:     }
238: 
239:     private static function syncKladrAddress($address, PartnerRegisterData $data): void
240:     {
241:         if ($data->company->address->street || $data->company->address->house) {
242:             $address->kladrAddress()->updateOrCreate(
243:                 ['address_id' => $address->id],
244:                 [
245:                     'house_fias_id' => $data->company->address->house?->getFiasId(),
246:                     'house_kladr_id' => $data->company->address->house?->kladr_id,
247:                     'street_data' => $data->company->address->street?->toArrayForStorage(),
248:                     'house_data' => $data->company->address->house?->toArrayForStorage(),
249:                 ]
250:             );
251:         }
252:     }
253: 
254:     private static function createShop(Seller $seller, PartnerRegisterData $data): Shop
255:     {
256:         return $seller->shops()->create([
257:             'name' => $data->company->shop_name,
258:             'is_active' => true,
259:         ]);
260:     }
261: 
262:     private static function updateShop(Seller $seller, PartnerRegisterData $data): void
263:     {
264:         $shop = $seller->shops()->first();
265: 
266:         if ($shop) {
267:             $shop->update([
268:                 'name' => $data->company->shop_name,
269:             ]);
270:         } else {
271:             self::createShop($seller, $data);
272:         }
273:     }
274: 
275:     private static function addUserToTeam(User $user, Seller $seller, PartnerRegisterData $data): void
276:     {
277:         Team::create([
278:             'user_id' => $user->id,
279:             'seller_id' => $seller->id,
280:         ]);
281:     }
282: }
```

## Modules/Sales/app/Repositories/Sellers/SellerRepository.php

SHA-256 `8861dd517ca214fa389eef93f0c6af2917329e04cdca0d816ed45b0f3a5a4144`; 31 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace Sales\Repositories\Sellers;
6: 
7: use App\Enums\Common\Moderation\ModerationStatus;
8: use Sales\Models\Seller\SalesModeration;
9: use Sales\Models\Seller\Seller;
10: 
11: final class SellerRepository
12: {
13:     public static function getPendingVerification(Seller $seller): ?SalesModeration
14:     {
15:         return SalesModeration::where('seller_id', $seller->id)
16:             ->where('moderatable_type', Seller::class)
17:             ->where('moderatable_id', $seller->id)
18:             ->where('status', ModerationStatus::Pending)
19:             ->orderBy('created_at', 'desc')
20:             ->first();
21:     }
22: 
23:     public static function getLastVerification(Seller $seller): ?SalesModeration
24:     {
25:         return SalesModeration::where('seller_id', $seller->id)
26:             ->where('moderatable_type', Seller::class)
27:             ->where('moderatable_id', $seller->id)
28:             ->orderBy('created_at', 'desc')
29:             ->first();
30:     }
31: }
```

## Modules/Sales/composer.json

SHA-256 `ae9586a29a0218502c4f79f3a6b75dc7b37462c9b18c0f1fb7a19e56ab369ace`; 29 lines.

```text
1: {
2:     "name": "unbox/sales",
3:     "description": "",
4:     "authors": [
5:         {
6:             "name": "Dmitry Vostrikov",
7:             "email": "vostrikovdv@yandex.ru"
8:         }
9:     ],
10:     "extra": {
11:         "laravel": {
12:             "providers": [],
13:             "aliases": {}
14:         }
15:     },
16:     "autoload": {
17:         "psr-4": {
18:             "Sales\\": "app/",
19:             "Sales\\Database\\Factories\\": "database/factories/",
20:             "Sales\\Database\\Seeders\\": "database/seeders/",
21:             "Sales\\Database\\Migrations\\": "database/migrations/"
22:         }
23:     },
24:     "autoload-dev": {
25:         "psr-4": {
26:             "Sales\\Tests\\": "tests/"
27:         }
28:     }
29: }
```

## Modules/Sales/resources/views/livewire/components/forms/product/properties-form.blade.php

SHA-256 `e84dd27b73271761914a2ce15d04d584cc7aa3bb63c5e99d891c982628d9adda`; 339 lines.

```text
1: <div>
2:     <flux:card>
3:         <div class="border-b border-zinc-200 pb-4 mb-6">
4:             <flux:heading size="md">Свойства товара</flux:heading>
5:             <flux:subheading>Добавьте характеристики и выберите общие свойства</flux:subheading>
6:         </div>
7: 
8:         <div class="space-y-4">
9:             <flux:table>
10:                 <flux:table.columns>
11:                     <flux:table.column>Название</flux:table.column>
12:                     <flux:table.column>Тип</flux:table.column>
13:                     <flux:table.column>Отображение</flux:table.column>
14:                     <flux:table.column>Значения</flux:table.column>
15:                     <flux:table.column>Статус</flux:table.column>
16:                     <flux:table.column class="w-40">Действия</flux:table.column>
17:                 </flux:table.columns>
18: 
19:                 <flux:table.rows>
20:                     @forelse ($form->properties as $index => $property)
21:                         @php
22:                             $isShared = $property['is_shared'] ?? false;
23:                             $isPropertyUsed =
24:                                 isset($property['id']) && $product?->isPropertyUsedInOffers($property['id']);
25:                             $typeLabel =
26:                                 optional(
27:                                     \Catalog\Enums\Product\PropertyType::tryFrom($property['type'] ?? ''),
28:                                 )->getLabel() ?? '—';
29:                             $controlLabel =
30:                                 optional(
31:                                     \Catalog\Enums\Product\PropertyControl::tryFrom($property['control'] ?? ''),
32:                                 )->getLabel() ?? '—';
33:                             $values = collect($property['values'] ?? []);
34:                             $availableCodes = collect($property['available_codes'] ?? [])->filter();
35:                         @endphp
36:                         <flux:table.row>
37:                             <flux:table.cell>
38:                                 <div class="font-medium">{{ $property['name'] ?? 'Свойство' }}</div>
39:                                 <div class="mt-1">
40:                                     <flux:badge color="{{ $isShared ? 'blue' : 'zinc' }}" size="sm">
41:                                         {{ $isShared ? 'Базовое' : 'Магазин' }}
42:                                     </flux:badge>
43:                                 </div>
44:                             </flux:table.cell>
45:                             <flux:table.cell>{{ $typeLabel }}</flux:table.cell>
46:                             <flux:table.cell>{{ $controlLabel }}</flux:table.cell>
47:                             <flux:table.cell>
48:                                 @php
49:                                     $displayValues = $values;
50: 
51:                                     if ($availableCodes->isNotEmpty()) {
52:                                         $displayValues = $values->filter(
53:                                             fn($value) => !empty($value['code']) &&
54:                                                 $availableCodes->contains($value['code']),
55:                                         );
56:                                     }
57: 
58:                                     $displayValueLabels = $displayValues
59:                                         ->map(function ($value) {
60:                                             $label = $value['label'] ?? null;
61: 
62:                                             if (!$label) {
63:                                                 $label =
64:                                                     $value['value_text'] ??
65:                                                     ($value['value_integer'] ?? ($value['value_decimal'] ?? null));
66:                                             }
67: 
68:                                             if ($label === null || $label === '') {
69:                                                 return $value['code'] ?? null;
70:                                             }
71: 
72:                                             return (string) $label;
73:                                         })
74:                                         ->filter()
75:                                         ->values();
76: 
77:                                     $shownLabels = $displayValueLabels->take(6);
78:                                     $remainingCount = $displayValueLabels->count() - $shownLabels->count();
79:                                 @endphp
80: 
81:                                 @if ($shownLabels->isNotEmpty())
82:                                     <div class="flex flex-wrap items-center gap-2">
83:                                         @foreach ($shownLabels as $label)
84:                                             <flux:badge size="sm">{{ $label }}</flux:badge>
85:                                         @endforeach
86: 
87:                                         @if ($remainingCount > 0)
88:                                             <div class="text-sm text-zinc-600">
89:                                                 +{{ $remainingCount }}
90:                                             </div>
91:                                         @endif
92:                                     </div>
93:                                 @else
94:                                     <div class="text-sm text-zinc-600">
95:                                         {{ $values->count() }} знач.
96:                                     </div>
97:                                 @endif
98:                             </flux:table.cell>
99:                             <flux:table.cell>
100:                                 @if ($isPropertyUsed)
101:                                     <flux:badge color="yellow" size="sm">Используется</flux:badge>
102:                                 @else
103:                                     <flux:badge color="green" size="sm">Свободно</flux:badge>
104:                                 @endif
105:                             </flux:table.cell>
106:                             <flux:table.cell>
107:                                 <div class="flex flex-wrap gap-2">
108:                                     <flux:button size="sm" wire:click="openPropertyModal({{ $index }})"
109:                                         :disabled="!($product?->canChangeProperties() ?? true)">
110:                                         Редактировать
111:                                     </flux:button>
112:                                     <flux:button variant="danger" size="sm"
113:                                         wire:click="removeProperty({{ $index }})"
114:                                         :disabled="!($product?->canChangeProperties() ?? true) || $isPropertyUsed">
115:                                         Удалить
116:                                     </flux:button>
117:                                 </div>
118:                             </flux:table.cell>
119:                         </flux:table.row>
120:                     @empty
121:                         <flux:table.row>
122:                             <flux:table.cell colspan="6" class="text-center py-6 text-zinc-500">
123:                                 Свойства не добавлены
124:                             </flux:table.cell>
125:                         </flux:table.row>
126:                     @endforelse
127:                 </flux:table.rows>
128:             </flux:table>
129: 
130:             <div class="flex justify-end">
131:                 <flux:button variant="primary" wire:click="openPropertyModalForCreate"
132:                     :disabled="!($product?->canChangeProperties() ?? true)">
133:                     Добавить свойство
134:                 </flux:button>
135:             </div>
136:         </div>
137:     </flux:card>
138: 
139:     <div class="flex justify-end mt-6">
140:         <flux:button variant="primary" wire:click="saveProperties" :disabled="!$editable">
141:             <div class="flex items-center gap-2">
142:                 @svg('icon-save', 'size-4')
143:                 Сохранить свойства
144:             </div>
145:         </flux:button>
146:     </div>
147: 
148:     <!-- Модалка свойств -->
149:     <flux:modal wire:model="form.showPropertyModal" class="md:w-3xl">
150:         <div class="space-y-4">
151:             <div class="flex items-start justify-between">
152:                 <div>
153:                     <flux:heading size="lg">
154:                         {{ $form->propertyModalIndex === null ? 'Добавление свойства' : 'Редактирование свойства' }}
155:                     </flux:heading>
156:                     <flux:text>Сначала выберите базовое свойство или создайте собственное.</flux:text>
157:                 </div>
158:             </div>
159: 
160:             <div class="flex gap-2">
161:                 <flux:button
162:                     variant="{{ ($form->propertyDraft['mode'] ?? 'shared') === 'shared' ? 'primary' : 'ghost' }}"
163:                     size="sm" wire:click="setPropertyDraftMode('shared')"
164:                     :disabled="!($product?->canChangeProperties() ?? true)">
165:                     Базовое свойство
166:                 </flux:button>
167:                 <flux:button
168:                     variant="{{ ($form->propertyDraft['mode'] ?? 'shared') === 'custom' ? 'primary' : 'ghost' }}"
169:                     size="sm" wire:click="setPropertyDraftMode('custom')"
170:                     :disabled="!($product?->canChangeProperties() ?? true)">
171:                     Собственное свойство магазина
172:                 </flux:button>
173:             </div>
174: 
175:             @if (($form->propertyDraft['mode'] ?? 'shared') === 'shared')
176:                 @php
177:                     $availableProperties = $form->getAvailableSharedProperties();
178:                     $sharedOptions = collect($availableProperties)->map(function ($option) {
179:                         return [
180:                             'id' => isset($option['id']) ? (int) $option['id'] : null,
181:                             'name' => $option['name'] ?? 'Свойство',
182:                         ];
183:                     });
184:                     $selectedSharedId =
185:                         $form->propertyDraft['selected_property_id'] ?? ($form->propertyDraft['id'] ?? null);
186:                     $selectedSharedId = $selectedSharedId !== null ? (int) $selectedSharedId : null;
187:                     if ($selectedSharedId && $sharedOptions->where('id', $selectedSharedId)->isEmpty()) {
188:                         $sharedOptions = $sharedOptions->push([
189:                             'id' => $selectedSharedId,
190:                             'name' => $form->propertyDraft['name'] ?? 'Свойство',
191:                         ]);
192:                     }
193:                     $sharedValues = collect($form->propertyDraft['values'] ?? [])->filter(
194:                         fn($value) => !empty($value['code']),
195:                     );
196:                 @endphp
197:                 <div class="space-y-3">
198:                     <flux:field>
199:                         <flux:label>Базовое свойство каталога</flux:label>
200:                         <flux:select wire:model.live="form.propertyDraft.selected_property_id"
201:                             placeholder="Выберите свойство" :disabled="!($product?->canChangeProperties() ?? true)">
202:                             <flux:select.option value="">-- Выберите свойство --</flux:select.option>
203:                             @foreach ($sharedOptions->sortBy('name') as $option)
204:                                 <flux:select.option value="{{ (string) $option['id'] }}">
205:                                     {{ $option['name'] }}
206:                                 </flux:select.option>
207:                             @endforeach
208:                         </flux:select>
209:                         <flux:error name="form.propertyDraft.selected_property_id" />
210:                     </flux:field>
211: 
212:                     <flux:field>
213:                         <flux:label>Разрешенные значения (код)</flux:label>
214:                         @if ($sharedValues->isNotEmpty())
215:                             <flux:pillbox wire:model="form.propertyDraft.available_codes" multiple
216:                                 placeholder="Выберите значения по коду"
217:                                 :disabled="!($form->propertyDraft['selected_property_id'] ?? false)">
218:                                 @foreach ($sharedValues as $value)
219:                                     @php
220:                                         $label =
221:                                             $value['label'] ??
222:                                             ($value['value_text'] ??
223:                                                 ($value['value_integer'] ?? ($value['value_decimal'] ?? '')));
224:                                     @endphp
225:                                     <flux:pillbox.option value="{{ $value['code'] }}">
226:                                         <span class="text-zinc-500">{{ $label }}</span>
227:                                     </flux:pillbox.option>
228:                                 @endforeach
229:                             </flux:pillbox>
230:                         @else
231:                             <flux:callout variant="secondary" icon="information-circle">
232:                                 <flux:callout.text>У выбранного свойства пока нет доступных кодов значений.
233:                                 </flux:callout.text>
234:                             </flux:callout>
235:                         @endif
236:                         <flux:error name="form.propertyDraft.available_codes" />
237:                     </flux:field>
238:                 </div>
239:             @else
240:                 <div class="space-y-4">
241:                     <flux:field>
242:                         <flux:label>Название свойства</flux:label>
243:                         <flux:input wire:model="form.propertyDraft.name" placeholder="Введите название"
244:                             :disabled="!($product?->canChangeProperties() ?? true)" />
245:                         <flux:error name="form.propertyDraft.name" />
246:                     </flux:field>
247: 
248:                     <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
249:                         @php
250:                             $isColorpickerControl = ($form->propertyDraft['control'] ?? null) === 'colorpicker';
251:                         @endphp
252:                         <flux:field>
253:                             <flux:label>Тип данных</flux:label>
254:                             <flux:select wire:model.live="form.propertyDraft.type"
255:                                 :disabled="!($product?->canChangeProperties() ?? true) || $isColorpickerControl"
256:                                 placeholder="Выберите тип">
257:                                 @foreach (\Catalog\Enums\Product\PropertyType::cases() as $type)
258:                                     <flux:select.option value="{{ $type->value }}">{{ $type->getLabel() }}
259:                                     </flux:select.option>
260:                                 @endforeach
261:                             </flux:select>
262:                             <flux:error name="form.propertyDraft.type" />
263:                         </flux:field>
264:                         <flux:field>
265:                             <flux:label>Отображение</flux:label>
266:                             <flux:select wire:model.live="form.propertyDraft.control" placeholder="Выберите отображение"
267:                                 :disabled="!($product?->canChangeProperties() ?? true)">
268:                                 @foreach (\Catalog\Enums\Product\PropertyControl::cases() as $control)
269:                                     <flux:select.option value="{{ $control->value }}">
270:                                         {{ $control->getLabel() }}</flux:select.option>
271:                                 @endforeach
272:                             </flux:select>
273:                         </flux:field>
274:                     </div>
275: 
276:                     <div class="space-y-3">
277:                         <flux:heading size="sm">Значения</flux:heading>
278:                         @foreach ($form->propertyDraft['values'] ?? [] as $valueIndex => $value)
279:                             @php
280:                                 $config = match ($form->propertyDraft['type'] ?? 'text') {
281:                                     'text' => [
282:                                         'field' => 'value_text',
283:                                         'type' => 'text',
284:                                         'placeholder' => 'Значение текста',
285:                                     ],
286:                                     'integer' => [
287:                                         'field' => 'value_integer',
288:                                         'type' => 'number',
289:                                         'placeholder' => 'Целое число',
290:                                     ],
291:                                     'decimal' => [
292:                                         'field' => 'value_decimal',
293:                                         'type' => 'number',
294:                                         'placeholder' => 'Десятичное число',
295:                                     ],
296:                                     default => [
297:                                         'field' => 'value_text',
298:                                         'type' => 'text',
299:                                         'placeholder' => 'Значение текста',
300:                                     ],
301:                                 };
302:                                 $wireModel = "form.propertyDraft.values.{$valueIndex}.{$config['field']}";
303:                             @endphp
304:                             <div class="flex items-center gap-2">
305:                                 @if (($form->propertyDraft['control'] ?? null) === 'colorpicker')
306:                                     <x-colorpicker-input wire:model="{{ $wireModel }}"
307:                                         placeholder="{{ $config['placeholder'] }}" class="flex-1" :disabled="!($product?->canChangeProperties() ?? true)" />
308:                                 @else
309:                                     <flux:input wire:model="{{ $wireModel }}" type="{{ $config['type'] }}"
310:                                         placeholder="{{ $config['placeholder'] }}" class="flex-1"
311:                                         :disabled="!($product?->canChangeProperties() ?? true)" />
312:                                 @endif
313:                                 <flux:button size="sm" wire:click="removeDraftValue({{ $valueIndex }})"
314:                                     :disabled="!($product?->canChangeProperties() ?? true)">
315:                                     Удалить
316:                                 </flux:button>
317:                             </div>
318:                         @endforeach
319: 
320:                         <flux:button variant="primary" size="sm" wire:click="addDraftValue"
321:                             :disabled="!($product?->canChangeProperties() ?? true)">
322:                             Добавить значение
323:                         </flux:button>
324:                     </div>
325:                 </div>
326:             @endif
327: 
328:             <div class="flex justify-end gap-2">
329:                 <flux:button type="button" wire:click="$set('form.showPropertyModal', false)">
330:                     Отмена
331:                 </flux:button>
332:                 <flux:button variant="primary" type="button" wire:click="savePropertyFromModal"
333:                     :disabled="!($product?->canChangeProperties() ?? true)">
334:                     Сохранить
335:                 </flux:button>
336:             </div>
337:         </div>
338:     </flux:modal>
339: </div>
```

## Modules/Sales/resources/views/livewire/components/product-form.blade.php

SHA-256 `998912009ee0afa0eb9ba570645b43fbd879b7a49204cbc7384aac60ee8964fd`; 136 lines.

```text
1: <div>
2:     <div class="max-w-6xl mx-auto space-y-6">
3:         <!-- Заголовок -->
4:         <div class="flex items-center justify-between">
5:             <div>
6:                 <h1 class="text-2xl font-semibold text-gray-900">
7:                     {{ $product ? 'Редактирование товара' : 'Создание товара' }}
8:                 </h1>
9:                 <p class="mt-1 text-sm text-gray-600">
10:                     {{ $product ? 'Измените информацию о товаре' : 'Заполните информацию о новом товаре' }}
11:                 </p>
12:             </div>
13:         </div>
14: 
15:         @if ($product)
16:             <div x-data="{ activeTab: @js($activeTab) }">
17:                 <flux:tab.group x-init="$watch('activeTab', (value) => { const group = $el.closest('ui-tab-group'); if (group) group.showPanel(value); });
18:                 $nextTick(() => { const group = $el.closest('ui-tab-group'); if (group) group.showPanel(activeTab); })">
19:                     <flux:tabs class="mb-6">
20:                         <flux:tab name="main" wire:click="setActiveTab('main')" :selected="$activeTab === 'main'"
21:                             x-on:click="activeTab = 'main'">
22:                             Основная информация
23:                         </flux:tab>
24:                         <flux:tab name="media" wire:click="setActiveTab('media')"
25:                             :selected="$activeTab === 'media'" x-on:click="activeTab = 'media'">
26:                             Изображения
27:                         </flux:tab>
28:                         <flux:tab name="content" wire:click="setActiveTab('content')"
29:                             :selected="$activeTab === 'content'" x-on:click="activeTab = 'content'">
30:                             Контент
31:                         </flux:tab>
32:                         <flux:tab name="classification" wire:click="setActiveTab('classification')"
33:                             :selected="$activeTab === 'classification'" x-on:click="activeTab = 'classification'">
34:                             Классификация
35:                         </flux:tab>
36:                         <flux:tab name="characteristics" wire:click="setActiveTab('characteristics')"
37:                             :selected="$activeTab === 'characteristics'" x-on:click="activeTab = 'characteristics'">
38:                             Размеры и вес
39:                         </flux:tab>
40:                         <flux:tab name="properties" wire:click="setActiveTab('properties')"
41:                             :selected="$activeTab === 'properties'" x-on:click="activeTab = 'properties'">
42:                             Свойства
43:                         </flux:tab>
44:                         <flux:tab name="specifications" wire:click="setActiveTab('specifications')"
45:                             :selected="$activeTab === 'specifications'" x-on:click="activeTab = 'specifications'">
46:                             Спецификации
47:                         </flux:tab>
48:                         <flux:tab name="offers" wire:click="setActiveTab('offers')"
49:                             :selected="$activeTab === 'offers'" x-on:click="activeTab = 'offers'">
50:                             Товарные предложения
51:                         </flux:tab>
52:                     </flux:tabs>
53: 
54:                     <!-- Основная информация -->
55:                     <flux:tab.panel name="main">
56:                         @livewire(\Sales\Livewire\Components\Forms\Product\ProductMainForm::class, [
57:                             'productId' => $product->id,
58:                             'editable' => $editable,
59:                         ])
60:                     </flux:tab.panel>
61: 
62:                     <!-- Изображения -->
63:                     <flux:tab.panel name="media">
64:                         @livewire(\Sales\Livewire\Components\Forms\Product\MediaForm::class, [
65:                             'productId' => $product->id,
66:                             'editable' => $editable,
67:                         ])
68:                     </flux:tab.panel>
69: 
70:                     <!-- Контент -->
71:                     <flux:tab.panel name="content">
72:                         @livewire(\Sales\Livewire\Components\Forms\Product\ContentForm::class, [
73:                             'productId' => $product->id,
74:                             'editable' => $editable,
75:                         ])
76:                     </flux:tab.panel>
77: 
78:                     <!-- Классификация -->
79:                     <flux:tab.panel name="classification">
80:                         @livewire(\Sales\Livewire\Components\Forms\Product\ClassificationForm::class, [
81:                             'productId' => $product->id,
82:                             'editable' => $editable,
83:                         ])
84:                     </flux:tab.panel>
85: 
86:                     <!-- Размеры и вес -->
87:                     <flux:tab.panel name="characteristics">
88:                         @livewire(\Sales\Livewire\Components\Forms\Product\CharacteristicsForm::class, [
89:                             'productId' => $product->id,
90:                             'editable' => $editable,
91:                         ])
92:                     </flux:tab.panel>
93: 
94:                     <!-- Свойства -->
95:                     <flux:tab.panel name="properties">
96:                         @livewire(\Sales\Livewire\Components\Forms\Product\PropertiesForm::class, [
97:                             'productId' => $product->id,
98:                             'editable' => $editable,
99:                         ])
100:                     </flux:tab.panel>
101: 
102:                     <!-- Товарные предложения -->
103:                     <flux:tab.panel name="offers">
104:                         @livewire(\Sales\Livewire\Components\Forms\Product\OffersForm::class, [
105:                             'productId' => $product->id,
106:                             'editable' => $editable,
107:                         ])
108:                     </flux:tab.panel>
109: 
110:                     <!-- Справочники -->
111:                     <flux:tab.panel name="specifications">
112:                         @livewire(\Sales\Livewire\Components\Forms\Product\SpecificationForm::class, [
113:                             'productId' => $product->id,
114:                             'editable' => $editable,
115:                         ])
116:                     </flux:tab.panel>
117:                 </flux:tab.group>
118:             </div>
119:         @else
120:             <!-- Основная информация (без табов при создании) -->
121:             @livewire(\Sales\Livewire\Components\Forms\Product\ProductMainForm::class, [
122:                 'productId' => null,
123:                 'editable' => $editable,
124:             ])
125:         @endif
126: 
127:         @if ($product && $product->status === \Catalog\Enums\Product\Status::Draft)
128:             <div class="flex justify-end mt-6">
129:                 <flux:button variant="outline" :disabled="!$editable" wire:click="submitForModeration"
130:                     wire:key="submit-button">
131:                     Отправить на модерацию
132:                 </flux:button>
133:             </div>
134:         @endif
135:     </div>
136: </div>
```

## Modules/Sales/routes/web.php

SHA-256 `0c9aa78e9de8ba3e51845d6de1f65be7fb8cb64538aadf45a527bed8e075092e`; 38 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: use Illuminate\Support\Facades\Route;
6: use Sales\Livewire\Pages\Dashboard;
7: use Sales\Livewire\Pages\Home;
8: 
9: // Route::get('sales', Home::class)->name('sales.home');
10: Route::redirect('sales', '/sales/login');
11: 
12: Route::prefix('sales')->as('sales.')->middleware('auth-sales')->group(function () {
13:     Route::middleware(['auth', 'verified'])->group(function () {
14:         Route::get('dashboard', Dashboard::class)->name('dashboard');
15: 
16:         Route::prefix('catalog')->as('catalog.')->group(function () {
17:             Route::get('', Sales\Livewire\Pages\Catalog\CatalogIndex::class)->name('index');
18: 
19:             Route::prefix('products')->as('products.')->group(function () {
20:                 Route::get('', Sales\Livewire\Pages\Catalog\Products\ProductsIndex::class)->name('list');
21:                 Route::get('create', Sales\Livewire\Pages\Catalog\Products\ProductPage::class)->name('create');
22:                 Route::get('{product}/edit', Sales\Livewire\Pages\Catalog\Products\ProductPage::class)->name('edit');
23:             });
24: 
25:             Route::prefix('collections')->as('collections.')->group(function () {
26:                 Route::get('', Sales\Livewire\Pages\Catalog\Collections\CollectionsList::class)->name('list');
27:                 Route::get('create', Sales\Livewire\Pages\Catalog\Collections\CollectionPage::class)->name('create');
28:                 Route::get('{collection}/edit', Sales\Livewire\Pages\Catalog\Collections\CollectionPage::class)->name('edit');
29:             });
30:         });
31: 
32:         Route::prefix('teams')->as('teams.')->group(function () {
33:             Route::get('', Sales\Livewire\Pages\Teams\TeamList::class)->name('list');
34:         });
35:     });
36: });
37: 
38: require __DIR__.'/auth.php';
```

## app/Console/Commands/MakeDemo.php

SHA-256 `031fc24307f7f5528fe4755a662a0315890360f79d2b5b3bac8f9e46fe48e44f`; 469 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace App\Console\Commands;
6: 
7: use App\Enums\Common\Moderation\ModerationStatus;
8: use App\Models\User;
9: use Catalog\Database\Seeders\CategorySeeder;
10: use Catalog\Database\Seeders\CreateBrandsSeeder;
11: use Catalog\Models\Brand;
12: use Catalog\Models\Category;
13: use Catalog\Models\Collection\Collection;
14: use Catalog\Models\Product\Product;
15: use Catalog\Models\Product\ProductColor;
16: use Catalog\Models\Product\ProductProperty;
17: use Catalog\Models\Property;
18: use Catalog\Models\PropertyValue;
19: use Illuminate\Console\Command;
20: use Intervention\Image\Drivers\Gd\Driver;
21: use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
22: use Intervention\Image\ImageManager;
23: use RuntimeException;
24: use Sales\Enums\Shop\InventoryPolicy;
25: use Sales\Factories\Users\PartnerRegisterDataFactory;
26: use Sales\Models\Seller\SalesModeration;
27: use Sales\Models\Seller\Seller;
28: use Sales\Models\Seller\Team;
29: use Sales\Repositories\Products\ProductOffersRepository;
30: use Sales\Repositories\Sellers\RegisterRepository;
31: 
32: final class MakeDemo extends Command
33: {
34:     protected $signature = 'app:make-demo {--with-products : Generate demo products}';
35: 
36:     protected $description = 'Make demo data';
37: 
38:     public function handle(): void
39:     {
40:         $this->info('Creating demo data...');
41: 
42:         // 1. Регистрация продавца
43:         $this->info('Registering seller...');
44:         $user = User::where('email', 'seller@unbox.ru')->first();
45:         if (! $user) {
46:             $registerData = PartnerRegisterDataFactory::fromFaker();
47:             $registerData->user->email = 'seller@unbox.ru';
48:             $user = RegisterRepository::store($registerData);
49:         }
50: 
51:         // Получаем seller через Team
52:         $team = Team::where('user_id', $user->id)->first();
53:         if (! $team) {
54:             $this->error('Failed to create seller');
55: 
56:             return;
57:         }
58: 
59:         $seller = $team->seller;
60:         if (! $seller) {
61:             $this->error('Failed to get seller');
62: 
63:             return;
64:         }
65: 
66:         $this->info("Seller created: {$seller->name} (ID: {$seller->id})");
67: 
68:         // 2. Автоматическое подтверждение продавца
69:         $this->info('Approving seller...');
70:         $this->approveSeller($seller);
71: 
72:         // 3. Обновление категорий
73:         $this->info('Seeding categories...');
74:         (new CategorySeeder)->run();
75: 
76:         // 4. Обновление брендов
77:         $this->info('Seeding brands...');
78:         (new CreateBrandsSeeder)->run();
79: 
80:         // 5. Создание коллекций
81:         $this->info('Creating collections...');
82:         $collections = $this->createCollections($seller);
83: 
84:         // 6. Реализация generateOffers (будет использована позже)
85:         // Уже реализована в ProductOffersRepository
86: 
87:         // 7. Генерация товаров (опционально)
88:         if ($this->option('with-products')) {
89:             $this->info('Generating 100 products...');
90:             $this->generateProducts($seller, $collections);
91:         } else {
92:             $this->info('Skipping product generation (use --with-products to enable).');
93:         }
94: 
95:         $this->info('Demo data created successfully!');
96:     }
97: 
98:     private function approveSeller(Seller $seller): void
99:     {
100:         $moderation = SalesModeration::where('seller_id', $seller->id)
101:             ->where('moderatable_type', Seller::class)
102:             ->where('moderatable_id', $seller->id)
103:             ->latest()
104:             ->first();
105: 
106:         if ($moderation) {
107:             $moderation->update([
108:                 'status' => ModerationStatus::Approved,
109:                 'moderated_at' => now(),
110:             ]);
111:         }
112: 
113:         $seller->update(['verified' => true, 'is_active' => true]);
114:     }
115: 
116:     private function createCollections(Seller $seller): array
117:     {
118:         $shop = $seller->shops()->first();
119:         if (! $shop) {
120:             return [];
121:         }
122: 
123:         $collectionNames = [
124:             'Летняя коллекция 2024',
125:             'Зимняя коллекция 2024',
126:             'Новинки',
127:             'Распродажа',
128:             'Премиум коллекция',
129:         ];
130: 
131:         $collections = [];
132:         foreach ($collectionNames as $index => $name) {
133:             // Проверяем, существует ли коллекция с таким именем для этого магазина
134:             $collection = Collection::where('shop_id', $shop->id)
135:                 ->where('name', $name)
136:                 ->first();
137: 
138:             if (! $collection) {
139:                 $collection = Collection::create([
140:                     'shop_id' => $shop->id,
141:                     'name' => $name,
142:                     'description' => fake('ru_RU')->realText(200),
143:                     'sort_order' => $index + 1,
144:                     'is_active' => true,
145:                 ]);
146:             }
147: 
148:             // Автоматическое подтверждение коллекции
149:             $moderation = SalesModeration::where('seller_id', $seller->id)
150:                 ->where('moderatable_type', Collection::class)
151:                 ->where('moderatable_id', $collection->id)
152:                 ->latest()
153:                 ->first();
154: 
155:             if ($moderation) {
156:                 $moderation->update([
157:                     'status' => ModerationStatus::Approved,
158:                     'moderated_at' => now(),
159:                 ]);
160:             }
161: 
162:             $collection->update(['verified' => true]);
163:             $collections[] = $collection;
164:         }
165: 
166:         return $collections;
167:     }
168: 
169:     private function generateProducts(Seller $seller, array $collections): void
170:     {
171:         $shop = $seller->shops()->first();
172:         if (! $shop) {
173:             return;
174:         }
175: 
176:         $team = Team::where('seller_id', $seller->id)->first();
177:         if (! $team) {
178:             return;
179:         }
180: 
181:         $user = $team->user;
182:         if (! $user) {
183:             return;
184:         }
185: 
186:         // Получаем свойства
187:         $colorProperty = Property::where('code', 'color')->first();
188:         $sizeProperty = Property::where('code', 'size')->first();
189: 
190:         // Получаем категории и бренды
191:         $categories = Category::where('is_active', true)->get();
192:         $brands = Brand::all();
193: 
194:         // Названия товаров для модной одежды и аксессуаров
195:         $productTemplates = [
196:             // Одежда
197:             ['name' => 'Платье', 'isClothing' => true],
198:             ['name' => 'Блузка', 'isClothing' => true],
199:             ['name' => 'Рубашка', 'isClothing' => true],
200:             ['name' => 'Юбка', 'isClothing' => true],
201:             ['name' => 'Брюки', 'isClothing' => true],
202:             ['name' => 'Джинсы', 'isClothing' => true],
203:             ['name' => 'Пиджак', 'isClothing' => true],
204:             ['name' => 'Жакет', 'isClothing' => true],
205:             ['name' => 'Кардиган', 'isClothing' => true],
206:             ['name' => 'Свитер', 'isClothing' => true],
207:             ['name' => 'Толстовка', 'isClothing' => true],
208:             ['name' => 'Футболка', 'isClothing' => true],
209:             ['name' => 'Куртка', 'isClothing' => true],
210:             ['name' => 'Пальто', 'isClothing' => true],
211:             ['name' => 'Плащ', 'isClothing' => true],
212:             // Аксессуары
213:             ['name' => 'Сумка', 'isClothing' => false],
214:             ['name' => 'Рюкзак', 'isClothing' => false],
215:             ['name' => 'Кошелек', 'isClothing' => false],
216:             ['name' => 'Ремень', 'isClothing' => false],
217:             ['name' => 'Шарф', 'isClothing' => false],
218:             ['name' => 'Шапка', 'isClothing' => false],
219:             ['name' => 'Перчатки', 'isClothing' => false],
220:             ['name' => 'Очки', 'isClothing' => false],
221:             ['name' => 'Украшение', 'isClothing' => false],
222:             ['name' => 'Браслет', 'isClothing' => false],
223:         ];
224: 
225:         // Проверяем, сколько товаров уже создано для этого магазина
226:         $existingProductsCount = Product::where('shop_id', $shop->id)->count();
227:         $productsToCreate = max(0, 100 - $existingProductsCount);
228: 
229:         if ($productsToCreate === 0) {
230:             $this->info('Products already exist. Skipping product creation.');
231: 
232:             return;
233:         }
234: 
235:         $this->info("Creating {$productsToCreate} products (already exist: {$existingProductsCount})...");
236: 
237:         for ($i = 0; $i < $productsToCreate; $i++) {
238:             $template = fake()->randomElement($productTemplates);
239:             $isClothing = $template['isClothing'];
240:             $baseName = $template['name'];
241:             $productName = $baseName.' '.fake()->words(2, true);
242: 
243:             $product = Product::create([
244:                 'shop_id' => $shop->id,
245:                 'created_by_id' => $user->id,
246:                 'name' => $productName,
247:                 'brand_id' => $brands->random()?->id,
248:                 'description' => fake('ru_RU')->realText(300),
249:                 'sort_order' => $i + 1,
250:                 'inventory_policy' => InventoryPolicy::InStock,
251:                 'price' => fake()->randomFloat(2, 500, 50000),
252:                 'base_weight' => fake()->numberBetween(100, 2000),
253:                 'base_length' => fake()->numberBetween(20, 100),
254:                 'base_width' => fake()->numberBetween(20, 100),
255:                 'base_height' => fake()->numberBetween(5, 50),
256:                 'measure_ratio' => 1,
257:                 'condition' => \Catalog\Enums\Product\Condition::New,
258:                 'is_new' => fake()->boolean(70),
259:                 'is_sale' => fake()->boolean(30),
260:                 'is_discount' => fake()->boolean(20),
261:                 'is_hit' => fake()->boolean(10),
262:                 'is_active' => true,
263:                 'published_at' => now(),
264:             ]);
265: 
266:             // Привязка к категориям
267:             $productCategories = $categories->random(fake()->numberBetween(1, 3));
268:             $product->categories()->attach($productCategories->pluck('id'));
269: 
270:             // Привязка к коллекциям
271:             if (! empty($collections)) {
272:                 $productCollections = collect($collections)->random(fake()->numberBetween(1, 2));
273:                 $product->collections()->attach($productCollections->pluck('id'));
274:             }
275: 
276:             // Добавление изображений
277:             $this->addProductImages($product, fake()->numberBetween(2, 5));
278: 
279:             // Добавление свойств
280:             if ($isClothing && $colorProperty && $sizeProperty) {
281:                 // Для одежды: цвет + размер
282:                 $product->properties()->attach($colorProperty->id, ['available_codes' => null]);
283:                 $product->properties()->attach($sizeProperty->id, ['available_codes' => null]);
284:             } elseif ($colorProperty) {
285:                 // Для аксессуаров: только цвет или без свойств
286:                 if (fake()->boolean(70)) {
287:                     $product->properties()->attach($colorProperty->id, ['available_codes' => null]);
288:                 }
289:             }
290: 
291:             // Заполнение available_codes для базовых свойств
292:             $this->fillAvailableCodes($product, $colorProperty, $sizeProperty, $isClothing);
293: 
294:             // Генерация торговых предложений
295:             ProductOffersRepository::generateOffers($product);
296: 
297:             // Заполнение ProductColor для товаров с цветом
298:             if ($colorProperty) {
299:                 $hasColorProperty = ProductProperty::where('product_id', $product->id)
300:                     ->where('property_id', $colorProperty->id)
301:                     ->exists();
302:                 if ($hasColorProperty) {
303:                     $this->fillProductColors($product, $colorProperty);
304:                 }
305:             }
306: 
307:             if (($i + 1) % 10 === 0) {
308:                 $this->info("Created {$i} products...");
309:             }
310:         }
311:     }
312: 
313:     private function addProductImages(Product $product, int $count): void
314:     {
315:         $manager = $this->createImageManager();
316: 
317:         for ($i = 0; $i < $count; $i++) {
318:             // Создаем новое изображение 800x800
319:             $image = $manager->create(800, 800);
320: 
321:             // Устанавливаем случайный цвет фона в формате rgb()
322:             $bgR = fake()->numberBetween(200, 255);
323:             $bgG = fake()->numberBetween(200, 255);
324:             $bgB = fake()->numberBetween(200, 255);
325:             $image->fill("rgb({$bgR}, {$bgG}, {$bgB})");
326: 
327:             // Добавляем случайные фигуры
328:             for ($j = 0; $j < 5; $j++) {
329:                 $r = fake()->numberBetween(0, 255);
330:                 $g = fake()->numberBetween(0, 255);
331:                 $b = fake()->numberBetween(0, 255);
332:                 $x = fake()->numberBetween(0, 800);
333:                 $y = fake()->numberBetween(0, 800);
334:                 $size = fake()->numberBetween(50, 200);
335:                 $image->drawCircle(x: $x, y: $y, init: function ($circle) use ($size, $r, $g, $b) {
336:                     $circle->radius((int) ($size / 2))->background("rgb({$r}, {$g}, {$b})");
337:                 });
338:             }
339: 
340:             // Создаем безопасное имя файла только из ASCII символов
341:             $filename = 'product_image_'.uniqid('', true).'.png';
342:             $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
343: 
344:             // Сохраняем во временный файл
345:             $tempDir = sys_get_temp_dir();
346:             $tempPath = $tempDir.'/'.$filename;
347:             $image->toPng()->save($tempPath);
348: 
349:             // Добавляем в MediaLibrary с явным указанием имени файла
350:             $product->addMedia($tempPath)
351:                 ->usingName("Product Image {$i}")
352:                 ->usingFileName($filename)
353:                 ->toMediaCollection('images');
354: 
355:             // Удаляем временный файл
356:             @unlink($tempPath);
357:         }
358:     }
359: 
360:     private function fillAvailableCodes(Product $product, ?Property $colorProperty, ?Property $sizeProperty, bool $isClothing): void
361:     {
362:         // Для базовых свойств (color и size) заполняем available_codes значениями из PropertyValue
363:         if ($colorProperty) {
364:             $productProperty = ProductProperty::where('product_id', $product->id)
365:                 ->where('property_id', $colorProperty->id)
366:                 ->first();
367: 
368:             if ($productProperty) {
369:                 $colorValues = PropertyValue::where('property_id', $colorProperty->id)->get();
370:                 if ($colorValues->isNotEmpty()) {
371:                     $selectedColors = $colorValues->random(fake()->numberBetween(3, min(8, $colorValues->count())));
372:                     $availableCodes = $selectedColors->pluck('code')->toArray();
373: 
374:                     $productProperty->update([
375:                         'available_codes' => $availableCodes,
376:                     ]);
377:                 }
378:             }
379:         }
380: 
381:         if ($isClothing && $sizeProperty) {
382:             $productProperty = ProductProperty::where('product_id', $product->id)
383:                 ->where('property_id', $sizeProperty->id)
384:                 ->first();
385: 
386:             if ($productProperty) {
387:                 $sizeValues = PropertyValue::where('property_id', $sizeProperty->id)->get();
388:                 if ($sizeValues->isNotEmpty()) {
389:                     $selectedSizes = $sizeValues->random(fake()->numberBetween(4, min(10, $sizeValues->count())));
390:                     $availableCodes = $selectedSizes->pluck('code')->toArray();
391: 
392:                     $productProperty->update([
393:                         'available_codes' => $availableCodes,
394:                     ]);
395:                 }
396:             }
397:         }
398:     }
399: 
400:     private function fillProductColors(Product $product, Property $colorProperty): void
401:     {
402:         $productProperty = ProductProperty::where('product_id', $product->id)
403:             ->where('property_id', $colorProperty->id)
404:             ->first();
405: 
406:         if (! $productProperty || empty($productProperty->available_codes)) {
407:             return;
408:         }
409: 
410:         $availableCodes = $productProperty->available_codes;
411:         $colorValues = PropertyValue::where('property_id', $colorProperty->id)
412:             ->whereIn('code', $availableCodes)
413:             ->get();
414: 
415:         $manager = $this->createImageManager();
416: 
417:         foreach ($colorValues as $colorValue) {
418:             // Создаем изображение цвета на основе hex значения
419:             $hexColor = $colorValue->value_text ?? '#000000';
420: 
421:             // Создаем новое изображение 200x200
422:             $image = $manager->create(200, 200);
423: 
424:             // Заполняем цветом из hex
425:             $image->fill(color: $hexColor);
426: 
427:             // Создаем безопасное имя файла только из ASCII символов
428:             $filename = 'color_'.preg_replace('/[^a-zA-Z0-9_-]/', '_', $colorValue->code).'_'.uniqid('', true).'.png';
429:             $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
430: 
431:             // Сохраняем во временный файл
432:             $tempDir = sys_get_temp_dir();
433:             $tempPath = $tempDir.'/'.$filename;
434:             $image->toPng()->save($tempPath);
435: 
436:             $productColor = ProductColor::create([
437:                 'product_id' => $product->id,
438:                 'code' => $colorValue->code,
439:             ]);
440: 
441:             // Добавляем в MediaLibrary с явным указанием имени файла
442:             $productColor->addMedia($tempPath)
443:                 ->usingName("Color {$colorValue->label}")
444:                 ->usingFileName($filename)
445:                 ->toMediaCollection('default');
446: 
447:             // Удаляем временный файл
448:             @unlink($tempPath);
449:         }
450:     }
451: 
452:     /**
453:      * Создает ImageManager с доступным драйвером.
454:      */
455:     private function createImageManager(): ImageManager
456:     {
457:         // Пробуем использовать GD
458:         if (extension_loaded('gd') && function_exists('gd_info')) {
459:             return new ImageManager(new Driver());
460:         }
461: 
462:         // Пробуем использовать Imagick
463:         if (extension_loaded('imagick')) {
464:             return new ImageManager(new ImagickDriver());
465:         }
466: 
467:         throw new RuntimeException('Neither GD nor Imagick PHP extensions are available. Please install one of them.');
468:     }
469: }
```

## app/Livewire/Pages/Catalog/ProductPage.php

SHA-256 `6e86d36be001e90395a57dcccf2363fa787114b5f9a85ad4dec8226effad6f0a`; 299 lines.

```text
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace App\Livewire\Pages\Catalog;
6: 
7: use Catalog\Models\Product\Product;
8: use Catalog\Models\Product\ProductOffer;
9: use Catalog\Models\Property;
10: use Illuminate\Support\Collection;
11: use Livewire\Attributes\Computed;
12: use Livewire\Attributes\Layout;
13: use Livewire\Attributes\Url;
14: use Livewire\Component;
15: use Spatie\MediaLibrary\MediaCollections\Models\Media;
16: use Tiptap\Editor;
17: 
18: #[Layout('components.layouts.app.header')]
19: final class ProductPage extends Component
20: {
21:     public Product $product;
22: 
23:     /** @var array<int, int|null> */
24:     public array $selectedPropertyValues = [];
25: 
26:     #[Url(as: 'offer_id')]
27:     public ?int $selectedOfferId = null;
28: 
29:     public function mount(Product $product): void
30:     {
31:         $this->product = $product->load([
32:             'specifications.group',
33:             'blocks',
34:             'properties.values',
35:             'offers.propertyValues',
36:         ]);
37: 
38:         // Если есть offer_id в URL, используем его для инициализации
39:         if ($this->selectedOfferId) {
40:             $offer = $this->product->offers->firstWhere('id', $this->selectedOfferId);
41: 
42:             if ($offer) {
43:                 $this->initializeFromOffer($offer);
44: 
45:                 return;
46:             }
47:         }
48: 
49:         // Иначе инициализируем выбранные значения свойств из первого offer
50:         $firstOffer = $this->product->offers()->with('propertyValues')->first();
51: 
52:         if ($firstOffer) {
53:             $this->selectedOfferId = $firstOffer->id;
54:             $this->initializeFromOffer($firstOffer);
55:         } else {
56:             // Если нет offers, инициализируем пустыми значениями
57:             foreach ($this->product->properties as $property) {
58:                 $this->selectedPropertyValues[$property->id] = null;
59:             }
60:         }
61:     }
62: 
63:     #[Computed]
64:     public function currentOffer(): ?ProductOffer
65:     {
66:         if ($this->selectedOfferId) {
67:             return $this->product->offers->firstWhere('id', $this->selectedOfferId);
68:         }
69: 
70:         return $this->product->offers->first();
71:     }
72: 
73:     #[Computed]
74:     public function currentPrice(): ?float
75:     {
76:         if ($this->currentOffer) {
77:             return (float) $this->currentOffer->price;
78:         }
79: 
80:         return $this->product->price !== null ? (float) $this->product->price : null;
81:     }
82: 
83:     #[Computed]
84:     public function availableOffers(): Collection
85:     {
86:         return $this->product->offers()->with('propertyValues')->get();
87:     }
88: 
89:     /**
90:      * Получить доступные значения свойства с учетом available_codes для базовых свойств.
91:      */
92:     public function getAvailablePropertyValues(Property $property): Collection
93:     {
94:         $values = $property->values;
95: 
96:         // Если свойство базовое (shop_id === null), фильтруем по available_codes
97:         if ($property->shop_id === null) {
98:             // Получаем pivot данные из уже загруженной коллекции
99:             $productProperty = $this->product->properties->firstWhere('id', $property->id);
100: 
101:             if ($productProperty && $productProperty->pivot) {
102:                 // Accessor в ProductPropertyPivot гарантирует, что available_codes всегда массив
103:                 $availableCodes = $productProperty->pivot->available_codes;
104: 
105:                 if (is_array($availableCodes) && ! empty($availableCodes)) {
106:                     $values = $values->filter(function ($value) use ($availableCodes) {
107:                         return in_array($value->code, $availableCodes, true);
108:                     });
109:                 }
110:             }
111:         }
112: 
113:         return $values;
114:     }
115: 
116:     public function updatedSelectedOfferId($value): void
117:     {
118:         // При изменении offer_id из URL обновляем выбранные свойства
119:         if ($value) {
120:             $offer = $this->product->offers->firstWhere('id', $value);
121: 
122:             if ($offer) {
123:                 $this->initializeFromOffer($offer);
124:             }
125:         }
126:     }
127: 
128:     public function updatedSelectedPropertyValues($value, $propertyId): void
129:     {
130:         // Находим первый offer, который содержит выбранное значение свойства
131:         $selectedValueId = $value;
132: 
133:         if ($selectedValueId === null) {
134:             // Если значение сброшено, ищем offer с остальными выбранными значениями
135:             $this->findMatchingOffer();
136: 
137:             return;
138:         }
139: 
140:         // Ищем offer, который содержит новое выбранное значение
141:         $matchingOffer = $this->availableOffers->first(function (ProductOffer $offer) use ($propertyId, $selectedValueId) {
142:             // Проверяем, что offer содержит выбранное значение для этого свойства
143:             $hasSelectedValue = $offer->propertyValues->contains('id', $selectedValueId);
144: 
145:             if (! $hasSelectedValue) {
146:                 return false;
147:             }
148: 
149:             // Проверяем, что остальные выбранные значения также присутствуют в offer
150:             foreach ($this->selectedPropertyValues as $propId => $valId) {
151:                 if ($valId === null) {
152:                     continue;
153:                 }
154: 
155:                 if ($propId === $propertyId) {
156:                     continue; // Уже проверили выше
157:                 }
158: 
159:                 if (! $offer->propertyValues->contains('id', $valId)) {
160:                     return false;
161:                 }
162:             }
163: 
164:             return true;
165:         });
166: 
167:         if ($matchingOffer) {
168:             $this->selectedOfferId = $matchingOffer->id;
169: 
170:             // Обновляем остальные выбранные значения из найденного offer
171:             foreach ($this->product->properties as $property) {
172:                 if (! isset($this->selectedPropertyValues[$property->id])) {
173:                     $propertyValue = $matchingOffer->propertyValues->firstWhere('property_id', $property->id);
174:                     $this->selectedPropertyValues[$property->id] = $propertyValue?->id;
175:                 }
176:             }
177:         } else {
178:             // Если не нашли точное совпадение, просто обновляем выбранное значение
179:             // и пытаемся найти ближайший offer
180:             $this->findMatchingOffer();
181:         }
182:     }
183: 
184:     public function renderContent(array|string|null $content): string
185:     {
186:         if (empty($content)) {
187:             return '';
188:         }
189: 
190:         // Если content - это строка, пытаемся декодировать как JSON
191:         if (is_string($content)) {
192:             $decoded = json_decode($content, true);
193:             if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
194:                 $content = $decoded;
195:             } else {
196:                 // Если это не JSON, возвращаем как есть (обычный текст)
197:                 return e($content);
198:             }
199:         }
200: 
201:         // Если content - это массив (tiptap JSON), конвертируем в HTML
202:         if (is_array($content)) {
203:             $editor = new Editor([
204:                 'extensions' => [
205:                     new \Tiptap\Extensions\StarterKit,
206:                 ],
207:             ]);
208: 
209:             return $editor->setContent($content)->getHTML();
210:         }
211: 
212:         return '';
213:     }
214: 
215:     /**
216:      * Получить изображение для блока типа image.
217:      */
218:     public function getBlockImage($block): ?Media
219:     {
220:         $collectionName = 'content-images-' . $block->id;
221:         $media = $this->product->getMedia($collectionName)->first();
222: 
223:         return $media;
224:     }
225: 
226:     /**
227:      * Получить видео для блока типа video.
228:      */
229:     public function getBlockVideo($block): ?Media
230:     {
231:         $collectionName = 'content-videos-' . $block->id;
232:         $media = $this->product->getMedia($collectionName)->first();
233: 
234:         return $media;
235:     }
236: 
237:     /**
238:      * Получить превью изображение для блока типа video.
239:      */
240:     public function getBlockPreviewImage($block): ?Media
241:     {
242:         $collectionName = 'content-images-' . $block->id;
243:         $media = $this->product->getMedia($collectionName)->first();
244: 
245:         return $media;
246:     }
247: 
248:     public function addToCart(): void
249:     {
250:         // TODO: Implement add to cart functionality
251:         $this->dispatch('cart:add', offerId: $this->selectedOfferId);
252:     }
253: 
254:     public function render()
255:     {
256:         return view('livewire.pages.catalog.product-page');
257:     }
258: 
259:     private function initializeFromOffer(ProductOffer $offer): void
260:     {
261:         foreach ($this->product->properties as $property) {
262:             $propertyValue = $offer->propertyValues->firstWhere('property_id', $property->id);
263:             $this->selectedPropertyValues[$property->id] = $propertyValue?->id;
264:         }
265:     }
266: 
267:     private function findMatchingOffer(): void
268:     {
269:         // Ищем offer, который максимально соответствует выбранным значениям
270:         $bestMatch = null;
271:         $bestMatchScore = 0;
272: 
273:         foreach ($this->availableOffers as $offer) {
274:             $score = 0;
275: 
276:             foreach ($this->selectedPropertyValues as $propertyId => $valueId) {
277:                 if ($valueId === null) {
278:                     continue;
279:                 }
280: 
281:                 if ($offer->propertyValues->contains('id', $valueId)) {
282:                     $score++;
283:                 }
284:             }
285: 
286:             if ($score > $bestMatchScore) {
287:                 $bestMatchScore = $score;
288:                 $bestMatch = $offer;
289:             }
290:         }
291: 
292:         if ($bestMatch) {
293:             $this->selectedOfferId = $bestMatch->id;
294: 
295:             // Обновляем выбранные значения из найденного offer
296:             $this->initializeFromOffer($bestMatch);
297:         }
298:     }
299: }
```

## composer.json

SHA-256 `4e71e1ad4604f74799fcd3f291cb85dbde4fca128401f3fdf62465a5e134081a`; 142 lines.

```text
1: {
2:     "$schema": "https://getcomposer.org/schema.json",
3:     "name": "unbox/project",
4:     "type": "project",
5:     "description": "Unbox project",
6:     "keywords": [
7:         "laravel",
8:         "framework",
9:         "unbox"
10:     ],
11:     "authors": [
12:         {
13:             "name": "Dmitry Vostrikov",
14:             "email": "vostrikovdv@yandex.ru"
15:         }
16:     ],
17:     "license": "MIT",
18:     "require": {
19:         "php": "^8.4",
20:         "awcodes/filament-badgeable-column": "^3.0",
21:         "bavix/laravel-wallet": "^11.4",
22:         "bezhansalleh/filament-shield": "^4.0",
23:         "blade-ui-kit/blade-icons": "^1.8",
24:         "doctrine/dbal": "^4.3",
25:         "dotswan/filament-map-picker": "^2.0",
26:         "emreyarligan/enum-concern": "^1.0",
27:         "filament/filament": "^4.0",
28:         "filament/spatie-laravel-media-library-plugin": "^4.0",
29:         "hflabs/dadata": "^24.4",
30:         "intervention/image-laravel": "^1.5",
31:         "lab404/laravel-impersonate": "^1.7",
32:         "laravel/fortify": "^1.30",
33:         "laravel/framework": "^12.0",
34:         "laravel/reverb": "^1.0",
35:         "laravel/tinker": "^2.10.1",
36:         "livewire/flux": "^2.1.1",
37:         "livewire/flux-pro": "dev-main",
38:         "livewire/volt": "^1.7.0",
39:         "lukepolo/laracart": "^2.6",
40:         "maize-tech/laravel-encryptable": "^4.0",
41:         "matanyadaev/laravel-eloquent-spatial": "^4.5",
42:         "mhmiton/laravel-modules-livewire": "^5.2",
43:         "nunomaduro/essentials": "^1.0",
44:         "nwidart/laravel-modules": "^12.0",
45:         "power-components/livewire-powergrid": "^6.7",
46:         "spatie/laravel-data": "^4.18",
47:         "spatie/laravel-medialibrary": "^11.15",
48:         "spatie/laravel-menu": "^4.2",
49:         "spatie/laravel-query-builder": "^6.3",
50:         "spatie/laravel-sluggable": "^3.7",
51:         "spatie/livewire-filepond": "^1.5",
52:         "ueberdosis/tiptap-php": "^2.0",
53:         "wire-elements/pro": "@dev",
54:         "wirechat/wirechat": "^0.3"
55:     },
56:     "require-dev": {
57:         "barryvdh/laravel-ide-helper": "^3.6",
58:         "driftingly/rector-laravel": "^2.0",
59:         "fakerphp/faker": "^1.23",
60:         "larastan/larastan": "^3.7",
61:         "laravel/boost": "^1.2",
62:         "laravel/pail": "^1.2.2",
63:         "laravel/pint": "^1.18",
64:         "laravel/sail": "^1.41",
65:         "mockery/mockery": "^1.6",
66:         "nunomaduro/collision": "^8.6",
67:         "pestphp/pest": "^4.1",
68:         "pestphp/pest-plugin-browser": "^4.0",
69:         "pestphp/pest-plugin-laravel": "^4.0",
70:         "rector/rector": "^2.1"
71:     },
72:     "autoload": {
73:         "psr-4": {
74:             "App\\": "app/",
75:             "Database\\Factories\\": "database/factories/",
76:             "Database\\Seeders\\": "database/seeders/"
77:         }
78:     },
79:     "autoload-dev": {
80:         "psr-4": {
81:             "Tests\\": "tests/"
82:         }
83:     },
84:     "scripts": {
85:         "post-autoload-dump": [
86:             "Illuminate\\Foundation\\ComposerScripts::postAutoloadDump",
87:             "@php artisan package:discover --ansi",
88:             "@php artisan filament:upgrade"
89:         ],
90:         "post-update-cmd": [
91:             "@php artisan vendor:publish --tag=laravel-assets --ansi --force"
92:         ],
93:         "post-root-package-install": [
94:             "@php -r \"file_exists('.env') || copy('.env.example', '.env');\""
95:         ],
96:         "post-create-project-cmd": [
97:             "@php artisan key:generate --ansi",
98:             "@php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\"",
99:             "@php artisan migrate --graceful --ansi"
100:         ],
101:         "dev": [
102:             "Composer\\Config::disableProcessTimeout",
103:             "npx concurrently -c \"#93c5fd,#c4b5fd,#fb7185,#fdba74\" \"php artisan serve\" \"php artisan queue:listen --tries=1\" \"php artisan pail --timeout=0\" \"npm run dev\" --names=server,queue,logs,vite --kill-others"
104:         ],
105:         "test": [
106:             "@php artisan config:clear --ansi",
107:             "@php artisan test"
108:         ]
109:     },
110:     "extra": {
111:         "laravel": {
112:             "dont-discover": []
113:         },
114:         "merge-plugin": {
115:             "include": [
116:                 "Modules/*/composer.json"
117:             ]
118:         }
119:     },
120:     "repositories": [
121:         {
122:             "type": "path",
123:             "url": "./packages/flux-pro"
124:         },
125:         {
126:             "type": "path",
127:             "url": "./packages/wire-elements-pro"
128:         }
129:     ],
130:     "config": {
131:         "optimize-autoloader": true,
132:         "preferred-install": "dist",
133:         "sort-packages": true,
134:         "allow-plugins": {
135:             "pestphp/pest-plugin": true,
136:             "php-http/discovery": true,
137:             "wikimedia/composer-merge-plugin": true
138:         }
139:     },
140:     "minimum-stability": "beta",
141:     "prefer-stable": true
142: }
```
