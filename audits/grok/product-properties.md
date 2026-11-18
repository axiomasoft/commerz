# Свойства товаров: поля, опции, атрибуты, типы, варианты

## 1. Четыре разных вещи, а не одно «свойство»

| Вид | Пример | Создаёт вариант (SKU)? | Хранение | Кто валидирует |
|---|---|---|---|---|
| **Фиксированное поле** | sku, barcode, вес, габариты, налоговый класс, `requires_shipping` | — | колонки `cmz_variants` | схема БД + DTO |
| **Опция (ось варианта)** | цвет, размер, объём памяти | **да** | `cmz_options`, `cmz_option_values`, `cmz_variant_option_values` | составные FK + уникальная сигнатура |
| **Атрибут (описательный)** | материал, страна, мощность, «водонепроницаемый», уход | нет | `attributes jsonb` на товаре/варианте + проекция фасетов | `AttributeType`-плагин |
| **Персонализация строки** | гравировка, подарочная открытка | нет (та же номенклатура) | `cmz_cart_lines.customization` → снимок в заказе | `ProductKind`/плагин персонализации |

Это совпадает с фактической гибридной моделью Unbox (колонки габаритов, словари опций, JSON спецификаций —
[analysis-unbox.md](../../docs/discovery/2026-10-08/analysis-unbox.md) §10) — мы её формализуем, а не заменяем
универсальным EAV. Различие «опция ≠ спецификация» — главный урок Unbox (§8 п.5), Shopify (options vs metafields),
Shopware (properties vs options) и Saleor (variant selection attributes).

## 2. Тип товара (семейство)

`cmz_product_types` задаёт: `kind` (физический/цифровой/услуга/набор — плагин `ProductKind`), `has_variants`,
допустимые **оси** (`cmz_product_type_options`) и **атрибуты** с уровнем `product`|`variant` и обязательностью
(`cmz_product_type_attributes`). Аналог family Akeneo / product type Saleor и Shopify.

- **Атрибут объявляется ровно на одном уровне** внутри типа. Нет наследования «вариант переопределяет товар» и цепочки
  fallback channel→locale→global из ChatGPT ([architecture-appendix.md](../chatgpt/architecture-appendix.md) §6.4).
  Причина: наследование значений — источник большинства багов «почему на сайте старое значение»; при двух уровнях
  с объявленной принадлежностью его просто нет.
- **Значения по каналам не поддерживаем в v1.** Разные описания для разных каналов — редкий B2B-случай; решается
  отдельным товаром или атрибутом-плагином. Локализация — да (§4).

## 3. Опции и варианты

- Простой товар = **один вариант по умолчанию** с пустой сигнатурой `''`. В корзине и на складе всегда вариант —
  нет двух путей кода (Unbox: «устранить неоднозначность cart строки без offer», §8 п.1).
- `option_signature` = отсортированные по коду опции пары `color=red;size=m` → `UNIQUE (product_id, option_signature)`.
  Сигнатура строится из кодов, не подписей; хранится текстом (видна в SQL, без коллизий хеша).
- Принадлежность «значение ∈ опции ∈ осям товара ∈ товар» — **три составных FK** без триггеров (prototype E1).
- Генерация вариантов: явный список комбинаций; помощник `GenerateVariants` строит декартово произведение только с
  **бюджетом** (по умолчанию ≤ 100) и предпросмотром количества. Никакой автогенерации всех сочетаний (Unbox §9).
- Разрешённое подмножество значений на товар (`cmz_product_option_values`) — для общих справочников цветов
  (идея `available_codes` Unbox).
- Смена набора осей у товара с продажами — запрещена командой; путь — новый товар или миграционная команда.
- Картинки варианта по цвету — `cmz_product_media.option_value_id` (идея `ProductColor` Unbox).

## 4. Атрибуты: документ + типы + проекция

### 4.1. Документ

```json
{
  "material": "cotton",
  "country_of_origin": "TR",
  "features": ["waterproof", "breathable"],
  "power_w": "1200",
  "screen": {"value": "6.1", "unit": "in"},
  "care": {"ru": "Стирка при 30°", "en": "Wash at 30°"},
  "release_date": "2026-09-01"
}
```

Правила: ключ — `cmz_attributes.code`; нет ключа = нет значения; `null` запрещён (очистка = удаление ключа);
`decimal`/`money` — строки/`{minor,currency}` (без float); локализуемый текст — карта локалей; значения `select` —
коды `cmz_attribute_choices`, не подписи.

### 4.2. Тип атрибута — плагин

```php
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface AttributeType
{
    public function key(): string;                                     // 'decimal', 'acme.color_swatch'
    public function configClass(): string;                             // class-string<AttributeConfig>
    public function normalize(mixed $raw, AttributeConfig $config): AttributeValue;   // бросает InvalidAttributeValue
    public function toDocument(AttributeValue $value): string|int|bool|array;
    public function fromDocument(string|int|bool|array $stored, AttributeConfig $config): AttributeValue;
    /** @return list<AttributeIndexRow> для cmz_product_attribute_index; [] — не фильтруемый */
    public function project(AttributeValue $value): array;
    public function capabilities(): AttributeCapabilities;            // equals, range, contains, sortable
}
```

Встроенные ключи совпадают с `legacy-platform-custom-fields` (`FieldValueValidator.php:189–205`): `text`, `int`, `decimal`,
`bool`, `date`, `datetime`, `select`, `multiselect`, `reference`, `money`, `url`, `email`, плюс специфичные для
каталога `measure` (число + каноническая единица: длина/масса/объём/мощность, конверсия в каноническую при записи)
и `localized_text`. Разделение «тип хранения / смысл / виджет» (ecosystem-conventions §7) соблюдено: `measure` —
тип, «размер экрана» — атрибут с `unit=in`, слайдер — забота админки.

### 4.3. Проекция для фильтров

Для `is_filterable` атрибутов обработчик записи товара в **той же транзакции** перезаписывает строки
`cmz_product_attribute_index` (product_id, variant_id, attribute_id, choice_id | num | flag | day | term).
Фасеты считают `DISTINCT product_id`; атрибуты уровня `variant` и опции фильтруются **по одному варианту**
(sameVariant, prototype E8 показывает ложное срабатывание наивного product-level запроса).

### 4.4. Жизненный цикл определения

| Действие | Разрешено |
|---|---|
| Переименовать подпись, добавить выбор, ослабить правило | всегда |
| Ужесточить правило (min/max, обязательность) | только с проверкой существующих значений (команда сообщает нарушения) |
| Сменить `type` при наличии значений | **нет** → новый атрибут + команда конвертации (v1.1) |
| `deprecated` | значения читаются, новые не пишутся |
| `retired` | скрыт везде, значения остаются в документе (история), проекция удалена |
| Плагин типа удалён | атрибуты типа → `unsupported`, документ не трогается, загрузка не падает, запись запрещена |

## 5. Почему не typed EAV и не legacy-platform-custom-fields «как есть»

| Вопрос | Typed EAV (ChatGPT §6.3, Magento, Bagisto) | `legacy-platform-custom-fields` | Наш гибрид |
|---|---|---|---|
| Карточка товара | N строк × JOIN | 1 строка JSONB | 1 строка + опции |
| Импорт 100k товаров × 30 атрибутов | 3 млн строк значений | 100k документов | 100k документов + строки проекции только фильтруемых |
| Фильтр «мощность ≥ 1000» | индекс по `value_decimal` | managed index DDL на поле (асинхронный воркер) | `(attribute_id, num)` в проекции |
| Оси вариантов, уникальность комбинации | не выражаются | не выражаются | составные FK + UNIQUE |
| Локализация значений | отдельный scope-ключ на строку | нет | карта локалей в значении |
| Целостность выбора `select` | FK на option | строка без FK | код проверяется типом; проекция хранит `choice_id` (FK) |
| Зависимость | — | требует legacy-platform, PG, ключ `(entity, record_id)` строками | — |

`legacy-platform-custom-fields` остаётся отличным решением для **доп. полей заказов, покупателей, складов** в Flex-продуктах —
через мост `commerz-legacy-platform`. Для каталога нужна семантика (оси, типы товаров, фасеты), которой у него нет и не должно быть
на уровне L0 legacy-platform.

## 6. Unbox и «не больше шести сложных свойств»

Если в проекте 6 сложных характеристик — это 6 атрибутов (возможно, своего `AttributeType`-плагина, например
`measure_range`) или 2–3 оси вариантов. Никаких новых таблиц и десятков definitions. Если характеристика —
самостоятельная сущность со связями (сертификат, модель двигателя) — `reference`-атрибут на сущность хоста.
Критерий отказа от нашей схемы из logical-schema-candidate §12 («потребитель с шестью полями вынужден создавать десятки
definitions») не срабатывает: 6 полей = 6 строк `cmz_attributes`.

## 7. Как делают другие

| Проект | Опции | Атрибуты | Наблюдение |
|---|---|---|---|
| Shopify | 3 опции (сейчас до 2048 вариантов), option values | metafields с типами и definitions | ось ≠ описание — та же граница |
| Saleor | variant selection attributes | attributes + values, input types, через product type | семейство через тип — взято |
| Akeneo | variant axes в family variant | families, attribute groups, scopable × localizable | channel-scope значений — мощно, но не нужно v1 |
| Sylius | ProductOption/OptionValue | EAV `AttributeValue` с typed полями и translations (`Component/Attribute/Model/AttributeValue.php`) | EAV на переводах — тяжело на импорт |
| Lunar | ProductOption/Value | `attribute_data` JSON + FieldType классы (`Base/FieldType.php`, `Base/Casts/AsAttributeData.php`) | FQCN в данных — хрупко при рефакторинге; у нас ключ типа |
| Bagisto | configurable через атрибуты | EAV с колонками `text_value`, `integer_value`… | смешение осей и описаний |
| Shopware | property groups (фильтр) + options (варианты) | custom fields JSON | разделение фильтр/вариант — взято |
| Medusa v2 | options/values | только `metadata` | слишком бедно для каталога |
| Magento | configurable attributes | полный EAV по типовым таблицам | эталон сложности, которой избегаем |
