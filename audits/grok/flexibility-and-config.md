# Гибкость и настройка: что данными, что конфигом, что кодом

«Максимальная гибкость» без правил = непроверяемая комбинаторика (design-hypotheses §13: «максимум конфигурации
повышает DX — опровергнуть»). Наш принцип: **гибкость данных — максимальная, гибкость конфига — минимальная и
типизированная, гибкость поведения — через закрытый набор SPI.**

## 1. Матрица

| Что меняется | Механизм | Кто меняет | Пример |
|---|---|---|---|
| Ассортимент, атрибуты, опции, типы товаров | **данные** (`cmz_attributes`, `cmz_product_types`) | менеджер в админке | новый атрибут «плотность ткани» без релиза |
| Цены, акции, купоны, налоговые ставки | **данные** | менеджер | акция 3=2 на категорию |
| Магазины, каналы, склады и их связи | **данные** | администратор | новый ПВЗ продаёт в канал «сайт» с приоритетом 2 |
| Тайминг резерва, TTL, округление, нумерация, политика возврата лимитов | **типизированный конфиг** (enum) | разработчик хоста | `ReservationTiming::OnCheckoutStart` |
| Новый тип атрибута, условие/эффект акции, активатор, стадия расчёта, стратегия размещения, шлюз оплаты, доставка, налог | **плагин** (SPI + регистрация в manifest) | разработчик | `acme.loyalty_points` эффект |
| Замена целого модуля (внешний склад, внешний прайсинг) | **реализация контракта ядра** + биндинг в контейнере | разработчик | `StockAvailability` → адаптер МойСклад |
| Реакция на бизнес-события | **события после коммита / outbox** | разработчик | `OrderPlaced` → письмо, CRM, Chatom |

Чего **нет**: подмены Eloquent-моделей, хуков «before/after всего», подписок, хранимых в БД, исполняемого кода в
конфиге/админке, магических строк FQCN в данных.

## 2. Конфигурация: один файл на пакет + типизированный аксессор

```php
// config/commerz.php — только скаляры, enum-значения и class-string; переживает config:cache
return [
    'connection'   => env('COMMERZ_DB_CONNECTION'),        // null = соединение по умолчанию
    'table_prefix' => 'cmz_',                              // фиксируется в cmz_schema_state при установке
    'currency_rounding' => 'half_up',                      // RoundingMode
    'availability' => 'inventory',                         // inventory | unlimited — без молчаливого дефолта
    'plugins'      => [                                    // порядок не важен: граф зависимостей
        Commerz\Coupons\CouponsPlugin::class,
    ],
];
```

```php
#[Api(since: '1.0')]
final readonly class CommerzConfig
{
    public function __construct(
        public ?string $connection,
        public string $tablePrefix,
        public RoundingMode $currencyRounding,
        public AvailabilitySource $availability,
        /** @var list<class-string<CommerzPlugin>> */
        public array $plugins,
    ) {}

    public static function fromRepository(Repository $config): self   // бросает InvalidConfiguration с путём ключа
    { /* … см. code-examples/Core/CommerzConfig.php */ }
}
```

- Неизвестное значение enum, несуществующий класс плагина, класс не того интерфейса → **исключение при загрузке**,
  с указанием ключа (`commerz.plugins.0`). Никакого `?? default` для значений, влияющих на деньги и остатки.
- `table_prefix` после установки сверяется с `cmz_schema_state`; несовпадение — ошибка (fingerprint Chatom,
  `chatom/packages/core/src/Schema/Data/SchemaSpace.php:45–77`).
- Конфиг модулей — свои файлы (`commerz-inventory.php`: `reservation_timing`, `reservation_ttl_minutes`,
  `allocation_strategy`; `commerz-sales.php`: `order_number_format`, `payment_due_minutes`; `commerz-promotions.php`:
  `usage_release_policy`).

## 3. Плагины: manifest → компиляция → заморозка

```php
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface CommerzPlugin
{
    public function manifest(): PluginManifest;            // id 'commerz.coupons', version, requires ['commerz.promotions']
    public function register(PluginRegistrar $registrar): void;   // только декларации, без I/O
}
```

1. При загрузке провайдер ядра создаёт плагины (`new $class()`, без контейнера — как Chatom `Plugin.php:11–14`).
2. `PluginGraphCompiler` проверяет: уникальность id, зависимости, циклы, уникальность ключей вкладов **по виду**
   (два `PromotionEffect` с ключом `line.percent_off` — ошибка), владение таблицами (существительное `x_<vendor>`).
3. Результат — неизменяемый `PluginRegistry`, кэшируемый в `bootstrap/cache/commerz_plugins.php`.
   В рантайме реестр только читается; регистрация после заморозки — исключение.
4. Реализации вкладов (условие, эффект…) разрешаются контейнером **лениво** по ключу — могут иметь зависимости.

`commerz:plugins` выводит: плагины, версии, вклады по видам, владельца каждого ключа, таблицы. Это ответ на
«doctor должно объяснять, почему выбрана конкретная discount policy» (ecosystem-conventions §6).

## 4. Конвейеры с якорями

Расчёт цены (architecture.md §5) и размещение заказа — конвейеры с **фиксированными якорями** (enum).
Плагин регистрирует стадию `before(PricingAnchor::Tax)` или `after(PricingAnchor::CartPromotions)`; при равных
якорях — порядок по `priority`, затем по ключу (детерминизм). Стадия получает и возвращает неизменяемое состояние.
Это компромисс между «закрытым конвейером» (негибко) и Magento-interceptors на любой метод (непредсказуемо):
точек вставки ~8, каждая документирована и покрыта контрактным тестом.

## 5. Почему без подмены моделей

Chatom (`config/chatom.php:53`) и Lunar (`ModelManifest`) разрешают заменить Eloquent-модель пакета своей.
Для commerce отказываемся:

1. Модели — `Internal/` (legacy-platform STR-006): заменяемая модель делает публичным весь её контракт, включая колонки.
2. Два плагина, заменяющие одну модель, — last-wins без диагностики (design-hypotheses §11 отмечает тот же риск).
3. Всё, ради чего модели подменяют, покрыто иначе: доп. данные — `attributes`/`meta`/свои таблицы плагина с FK;
   доп. поведение — события и SPI; иные правила — реализация контракта ядра.

## 6. Расширение данных плагином

- Плагин владеет **своими** таблицами `cmz_x_<vendor>_*` и может ссылаться FK на таблицы пакетов, от которых зависит.
- Плагин **не добавляет колонки** в чужие таблицы (Chatom: «packages do not write to tables owned by another package»,
  `chatom/AGENTS.md`). Нужно поле в заказе — своя таблица 1:1 или `meta`.
- Миграции плагина регистрируются через `PluginRegistrar::migrations(path)`; удаление плагина ≠ удаление данных:
  `commerz:plugins:disable` оставляет таблицы, сохранённые ключи становятся `unsupported` (fail-closed).

## 7. Многоарендность и встраивание — тоже не конфиг-флаг

Commerz не имеет переключателя `tenancy: shared|database`. Он работает на соединении, которое ему дают
(`connection` или `ConnectionResolver` хоста), и не кэширует контекст в singletons — значит корректен в Octane/
очередях при DB-per-tenant (legacy-platform ADR-004). Подробно — architecture.md §6.

## 8. Тестируемость гибкости

`commerz-testkit` для авторов плагинов (по образцу AzGuard `Testing/Contracts/PluginContractTests.php`):
- `AttributeTypeContractTests` — round-trip `normalize → toDocument → fromDocument`, отказ на мусоре, проекция;
- `PromotionEffectContractTests` — чистота (дважды одинаковый результат), без отрицательных сумм, сумма аллокаций;
- `PaymentGatewayContractTests` — повтор webhook идемпотентен, таймаут ≠ отказ;
- `PluginManifestContractTests` — компиляция в пустом приложении, отсутствие сайд-эффектов `register()`.
