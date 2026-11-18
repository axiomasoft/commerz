# Commerz: фактические соглашения соседних пакетов

Дата: 2026-10-08. Статус: исследование и предложения для обсуждения, не утверждённая архитектура Commerz. Автор: Codex, поток `ecosystem`.

Изучены Chatom, Vaulter, AzGuard: Composer manifests, конфигурация, schema helpers и миграции, публичные контракты, реестры, plugins, операции, tenancy, ошибки и архитектурные тесты. Исходники соседних проектов не изменялись; их тесты, миграции и команды установки не запускались. Наличие кода и теста здесь означает «реализовано / тест предусмотрен», а не «проверка успешно выполнена».

Применён `architect:codebase-design`: отбираются небольшие интерфейсы, за которыми находятся реальные инварианты; сходство имён классов не считается достаточным основанием для заимствования. Исследование не утверждает, что все найденные решения корректны или пригодны для commerce без изменений.

## Состояние источников и воспроизводимость

| Репозиторий | HEAD в начале чтения | Состояние в начале | HEAD при сохранении evidence | Состояние при сохранении |
| --- | --- | --- | --- | --- |
| Chatom | `cc927a6adc2050384a37117bf99637fac6321c22` | dirty, 111 записей status | `8d6b2ac050955ed91696c92b2d364c3a53629eb3` | clean |
| Vaulter | `f1af3d883030407bb954e98156ff7a8baaf59660` | clean | тот же | clean |
| AzGuard | `ba02a9a422aa76d20333129b430861ca052258d4` | dirty, 81 запись status | тот же | dirty, 81 запись |

Chatom изменился другим процессом во время чтения. Поэтому это исследование **рабочих деревьев**, а не ревью трёх неизменяемых release tags. Для проверки сохранены выдержки с оригинальными номерами строк и SHA-256 69 ключевых файлов:

- [Chatom: код и выдержки](ecosystem/chatom-evidence.md), [Composer snapshot в начале](ecosystem/chatom-snapshot.json).
- [Vaulter: код и выдержки](ecosystem/vaulter-evidence.md), [Composer snapshot в начале](ecosystem/vaulter-snapshot.json).
- [AzGuard: код и выдержки](ecosystem/azguard-evidence.md), [Composer snapshot в начале](ecosystem/azguard-snapshot.json).
- [Общий evidence manifest](ecosystem/evidence-manifest.json). В `*-file-index.txt` — отфильтрованные tracked paths из `git ls-files`; vendor, generated front-end assets и секреты в эту выборку не включались.

Указанные далее пути `chatom/…`, `vaulter/…`, `azguard/…` относительны к `/home/vostrikov/projects/packages/`. Строки относятся к текущим файлам на дату исследования; сохранённые выдержки удерживают контекст, если соседние проекты продолжат изменяться.

## 1. Совместимость экосистемы означает независимое ядро и необязательные интеграции

**Реализовано.** Все три репозитория используют Composer monorepo `packages/<name>/`, PSR-4 и Laravel service providers. Однако состав и dependency graph различаются.

| Признак | Chatom | Vaulter | AzGuard |
| --- | --- | --- | --- |
| Core Composer name | `axiomasoft/chatom` | `axioma-studio/vaulter-core` | `axiomasoft/azguard` |
| PHP requirement | `^8.4` | `^8.4` | `^8.3` |
| Illuminate | `^12.0\|^13.0` | `^12.0\|^13.0` | `^11.0\|^12.0\|^13.0` |
| Namespace | `Chatom\\` | `Vaulter\\` | `AzGuard\\` |
| Optional packages | attachments, presence, search, poll, forum, voice-message, webhooks, broadcasting, testkit; full meta-package | media, drive, documents, filament, azgard | filament |
| Package boot | собственный service provider | `spatie/laravel-package-tools` | собственный service provider |

Основание: `packages/core/composer.json` каждого проекта и сохранённые Composer snapshots. Это **declared compatibility**, не результат проверки всей матрицы зависимостей.

Chatom запрещает core → companion dependency и обращения companion к `Internal`; forum и voice-message явно зависят от attachments. Ограничения проверяются в `chatom/tests/Arch/ArchitectureGatesTest.php:22,35,70,75`. Vaulter разделяет конкретный CAS/media, поведение drive и documents, но большая часть исторической схемы остаётся в core: это иной уровень зрелости физического разделения, его нельзя описывать как идентичную модель Chatom.

**Предложение Commerz.** Рабочая baseline-кандидатура — PHP 8.4 и Laravel 12/13, с фиксацией поддержки только после consumer matrix. Начать с хорошо разделённых модулей в одном core; отдельные Composer packages выделять по реальной независимости установки, ownership схемы и lifecycle. Наличие каталогов, цен, остатков и заказов само по себе не требует четырёх пакетов первого дня. Админка, платёжные провайдеры, поисковые движки и ecosystem bridges — сильные кандидаты на отдельные пакеты.

**Не копировать автоматически.** Package vendor/name сейчас неоднородны. `axiomasoft/commerz` выглядит согласованным с новым Chatom/AzGuard, но это предложение по naming, а не установленное правило. legacy-platform/Agentools не исследовались: для основных выводов прямой зависимости от них нет.

## 2. Короткие префиксы есть, единого значения или механизма нет

**Реализовано.**

| Пакет | Default table naming | Место разрешения | Вариативность |
| --- | --- | --- | --- |
| Vaulter | `v_` + logical name | `Configuration/DatabaseConfig::table()` | package connection и prefix; отдельный legacy map |
| AzGuard | `azg_` + base | `Storage` / `StorageRegistry` | именованные storages: connection + prefix + host key mode |
| Chatom | `chatom_` + logical name | `Spaces/Space`, `SchemaSpace::table()` | default + per-space prefix + per-table map + connection prefix |

Точные основания: `vaulter/packages/core/config/vaulter.php:35–46`; `vaulter/packages/core/src/Configuration/DatabaseConfig.php:17–41`; `azguard/packages/core/config/azguard.php:141–149`; `chatom/packages/core/src/Spaces/Space.php:512`; `chatom/packages/core/src/Schema/Data/SchemaSpace.php:23–42`.

Chatom отдельно различает имя, переданное Laravel, и полное физическое имя с connection prefix. `SchemaNames::identifier()` ограничивает имена 63 байтами, длинные сокращает детерминированным hash suffix (`chatom/…/SchemaNames.php:15–38`). AzGuard отвергает повторную регистрацию пары connection/prefix и несогласованные host keys (`azguard/…/StorageRegistry.php:30–59`). Это полезнее механического копирования `v_`.

**Предложение Commerz.** Обсуждать default `cmz_`: `cmz_products`, `cmz_variants`, `cmz_stock_movements`. Логические имена остаются читаемыми; коротким должен быть префикс пакета, а не каждое слово таблицы. Один resolver для schema, runtime models, raw queries и diagnostics. Если нужен table map, одна семантика override с явным приоритетом; учитывать connection prefix ровно один раз. Индексам и FK — стабильные короткие имена с детерминированным сокращением.

**Не копировать автоматически.** «Везде уже используются двухбуквенные префиксы» — неверно. Per-space table duplication Chatom решает конкретную задачу; создавать отдельные commerce tables на каждый магазин или tenant до выбора isolation model не следует. Tenant, store, sales channel и physical storage — разные оси.

## 3. Schema lifecycle — отдельный публичный контракт пакета

**Реализовано.** Chatom имеет `SchemaComponent` с `id()`, `tables()`, `steps()`, `dependsOn()`, `appliesTo()` и `SchemaStep::up()/down()`. Released steps задуманы неизменяемыми: миграции добавляются, существующие не переписываются. Step получает `SpaceSchema`, не читает произвольный runtime config. Компонент может менять только собственные таблицы; FK допустимы к явно объявленным dependencies. См. `chatom/packages/core/src/Schema/Contracts/SchemaComponent.php:10–40`, `SchemaStep.php:10–24`.

`SchemaSpace::fingerprint()` фиксирует ID strategy, prefix и table map после первой установки; connection не включается, чтобы смена хоста БД не считалась новой схемой (`…/SchemaSpace.php:45–77`). Generated migration shell ссылается на конкретные space/component/step (`chatom/packages/core/database/migrations/2026_10_01_000003_chatom_default_core_create_members.php:3–15`).

AzGuard хранит snapshot схемы в `storage_state` и сверяет его с настройками storage (`azguard/packages/core/src/Storage/Schema/StorageSchema.php:90–96`; `Storage.php:378–415`). Vaulter использует conventional migrations + helpers, включая upgrade history с rename и cleanup; `VaulterServiceProvider` регистрирует и запускает пакетные миграции (`vaulter/…/VaulterServiceProvider.php:21–35`).

**Предложение Commerz.** С первого дня определить владельца таблиц, семантику установки/upgrade/uninstall, неизменяемые migration inputs и baseline fingerprint. Для plugins нужна декларация принадлежащих им таблиц и DDL dependencies. Plugin не должен тайно добавлять обязательный столбец в order table, если core об этом ничего не знает. Disabled plugin с сохранёнными заказами и uninstall plugin — разные операции.

**Не копировать автоматически.** Полный schema compiler/repair engine Chatom — большая подсистема. Для первых commerce модулей могут быть достаточны обычные Laravel migrations и короткий registry ownership; архитектурное свойство важнее количества классов. Ни `config()` внутри старой миграции, ни изменяемый helper сами по себе не гарантируют воспроизводимый fresh install через два года.

## 4. Внутренний ID, host reference и контекст изоляции нужно разделять

**Реализовано.**

- Chatom выбирает `uuid7|ulid|bigint` для собственных rows, default `uuid7`. `ActorRef` отдельно содержит стабильный type alias и строковый ID длиной до 64 байт; он не привязан к конкретному `App\\Models\\User`. См. `chatom/…/Schema/Enums/IdStrategy.php:9–28`, `config/chatom.php:58–63`, `Actors/Data/ActorRef.php:11–30`.
- Vaulter default internal ID = ULID; host keys независимо выбираются из `string|bigint|uuid|ulid`; helper намеренно не создаёт FK к host tables. См. `vaulter/config/vaulter.php:39–48`, `Schema/HostKeyColumns.php:13–20`, `Schema/VaulterSchema.php:62–72`.
- AzGuard internal PK grant rows = bigint; subject/actor/tenant IDs имеют отдельный host key mode. `TenantRef` и `AssignmentScopeRef` образуют `AccessScope`: global assignment scope внутри tenant не равен global tenant. См. `azguard/…/Storage/Schema/StorageSchema.php:26–55`, `Kernel/Identity/AccessScope.php:7–26`, `TenantRef.php:9–45`.

Для Commerz это означает отдельные понятия `ProductId`, `VariantId`, `WarehouseId`, host `CustomerRef`/`ActorRef`, tenant, store/channel. Нельзя выводить принадлежность товара клиенту из совпадения его ID с host model ID. Один integer mode для host references ограничивает допустимые host types; это нужно считать частью контракта, а не незаметной оптимизацией.

**Реальная tenancy важнее комментариев.** В Vaulter `Schema/VaulterSchema.php:75–77` ещё сказано «Vaulter never scopes by it», но `Tenancy/TenancyBoundary.php:128–173` действительно накладывает tenant predicates, а `current():35–43` требует current tenant при strict mode. Queue middleware проверяет восстановленный контекст, а не переключает tenant само (`Jobs/Middleware/RestoreTenantContext.php:18–35`). Это конкретный пример расхождения комментария с реализацией.

**Предложение Commerz.** Перед схемой зафиксировать: tenant — владелец данных; channel/store — коммерческая проекция; warehouse/location — место хранения; operator/customer — внешние субъекты. В command/job передавать immutable context с tenant, actor, correlation и idempotency identity. Resolver хоста может поставлять контекст, но core обязан применять и проверять выбранную isolation policy во всех входах, включая очереди и CLI.

**Не копировать автоматически.** «Есть tenant_id» не доказывает isolation. Profile Vaulter, panel AzGuard и space Chatom имеют разную семантику; назвать Commerz сущность `Space` только ради сходства нельзя. Выбор UUID7/ULID/bigint — отдельное решение с migration и interoperability ценой.

## 5. Public API, SPI расширения и Internal действительно различаются

**Реализовано.** Chatom организован по доменным модулям; каждый может иметь `Data`, `Contracts`, `Internal`. `#[Api]` и `#[Spi(kind: CALL|IMPLEMENT)]` маркируют разные обещания совместимости. Пример: `Messages/Data/SendMessage` — `final readonly` command с typed identities; `Plugins/Contracts/Plugin` — реализуемый SPI. См. `chatom/…/Messages/Data/SendMessage.php:15–38`, `Plugins/Contracts/Plugin.php:15–20`.

AzGuard фиксирует `@api`, `@spi`, `@internal` в машинном API manifest; contracts без маркировки отвергаются генератором (`azguard/bin/api-manifest.php:5–14,85–94`). Его `Kernel` ограничен PHP и не должен обращаться к Laravel helpers — это проверяется тестами (`azguard/tests/Arch/ZonesArchTest.php:47–74`).

Vaulter сохраняет более выраженные горизонтальные папки `Actions`, `Repositories`, `Data`, `Contracts`, но вводит context-bound `Operations`; тест запрещает нескольким migrated HTTP controllers напрямую обращаться в Actions/Repositories (`vaulter/packages/core/tests/Arch/OperationsBoundaryTest.php:11–23`). Поэтому «вся экосистема строго одинаково устроена по папкам» — тоже неверно.

**Предложение Commerz.** Чистые value objects для количества, денег, ставок, IDs; typed commands/results на стабильном входе. HTTP request не должен быть обязательным аргументом inventory или checkout операции. Для внутренних persistence-классов — `Internal`; для plugin implementations — маленькие SPI contracts. Public API manifest с момента первого внешнего потребителя. Repository Read/Store split вводить там, где он концентрирует выборки/запись и invariant, а не по одному интерфейсу для каждого Eloquent метода.

**Не копировать автоматически.** `final readonly` вокруг Eloquent model не делает сам model immutable: Vaulter отдельно закрепляет исходный drive key (`Operations/OperationContext.php:12–40`). Для корзины и order quote лучше immutable value snapshot с чёткой версией, чем DTO со ссылкой на изменяемую модель. Framework-free package целиком не требуется для повторного использования в Laravel; достаточно действительно чистых вычислительных модулей.

## 6. Гибкость строится из типизированных contributions и управляемого lifecycle

**Реализовано.** Есть три разных механизма, а не единая «plugin система».

| Механизм | Что реально гарантирует | Источник |
| --- | --- | --- |
| Chatom `Plugin::manifest/register` | Pure declarations; Composer задаёт версии; requires/optional graph; deterministic topological order; duplicate/type/database checks | `Plugins/Contracts/Plugin.php:11–20`, `PluginManifest.php:10–38`, `Internal/PluginGraphCompiler.php:42–52,101–128` |
| Chatom `PluginRegistrar` | typed config/schema/stages/operations/drivers/models/event families, projection rebuilders, health checks, retention/purge, outbox consumers | `Plugins/PluginRegistrar.php:30–82,138–175,186–225` |
| AzGuard `Plugin::id/register/boot` | register в panel builder, boot после freeze; копия plugin на panel; explicit conflicts/dependencies; порядок attachment | `Contracts/Plugins/Plugin.php:11–35`, `Panels/PanelCompiler.php:73–148` |
| Vaulter `ProfileRegistry` | class-string provider, duplicate checks, clone + freeze snapshot | `Profiles/ProfileRegistry.php:22–69` |
| Vaulter `FileHandlerRegistry` | config FQCN + container tags + runtime registration; capability filtering; cache invalidation при register | `Handlers/FileHandlerRegistry.php:15–38,70–101` |

**Предложение Commerz.** В boot собирать declarations, проверять collisions и зависимости, затем получать immutable registry. В runtime выбирать implementations через подготовленный registry. Для long-lived workers не хранить текущую корзину/tenant в singleton registry. Исключения динамической регистрации разрешать только с понятным invalidation и isolation contract.

Plugin manifest должен описывать возможности и ownership, а не становиться вторым Composer. Важны стабильные namespaced contribution IDs, выбранный порядок, конфликт двух providers одной способности, диагностика происхождения настройки. `plugins:inspect`/doctor должно объяснять, почему выбрана конкретная discount policy.

**Не копировать автоматически.** Chatom разрешает plugin ID вида `acme.badge`, AzGuard приводит пример `acme/audit-trail`; это локальные соглашения. Не нужен универсальный hook `before/after everything`. Открытый registry `DocumentTypeRegistry` Vaulter просто заменяет array по ключу без duplicate/freeze проверки (`vaulter/packages/documents/src/Registries/DocumentTypeRegistry.php:7–22`); такой механизм непригоден как образец registry денежных правил.

## 7. Типизированные свойства можно проектировать, опираясь на уже существующие field descriptions

**Реализовано.** AzGuard `Schema/Field` описывает custom grant column или meta value: `string`, `int`, `bool`, `date`, `enum`, `model`, `required`, `multiple`, `rules`, `inMeta`. Методы builder возвращают clone, имена защищены от зарезервированных полей. `FieldSchema` выдаёт сериализуемое описание и `contributedBy`: model/provider/plugin/configure. См. `azguard/packages/core/src/Schema/Field.php:11–124`, `FieldSchema.php:13–32,63–70`.

**Предложение Commerz.** Взять идею **description → validation/normalization → persistence → presentation**, но разнести эти ответственности. `AttributeDefinition` не должен автоматически быть Filament field. Для UI, importer, API и query engine использовать одну machine-readable декларацию типа, cardinality, локализации, единиц измерения и происхождения. Сначала проверить реальными сценариями минимум decimal quantity, dimension/unit, single/multi option, localized text и external reference.

Имена обсуждаемых SPI: `AttributeType`/`AttributeCodec` для normalize/validate/encode/decode; отдельная поддержка filter/sort operators; registry стабильных type IDs и версий codecs. Важно различить storage type (decimal/text/reference), semantic type (длина/масса/цвет), widget (slider/select) и business constraint. Plugin «размер обуви» может переиспользовать decimal или enum storage, не создавая новый класс таблиц.

**Не копировать автоматически.** AzGuard Field — custom fields для grants, не зрелая product-attribute database. У него нет доказанной здесь модели перевода, effective dates, inherited variant values, единиц измерения, family templates, facets или миграции миллиона значений между типами. `inMeta` не решает индексацию каталога. JSON metadata допустимо как ограниченное дополнение; нельзя выводить из наличия `meta` пригодность JSON для любых фильтруемых товарных атрибутов.

## 8. Команды, транзакции, hooks и доставка событий имеют разные гарантии

**Реализовано.** Chatom `OperationRunner` разделяет phases:

```text
Prepare → Authorize → Validate
transaction: Lock → Receipt → Reauthorize → Persist → Project → Record
AfterCommit
```

No-op/replay не запускают повторно projection/record/after-commit; stage не может оставить другой transaction nesting level. `OperationContext` имеет неизменяемые space/connection/actor и typed facts, записываемые один раз; context не разделяется через container между вложенными операциями. Основание: `chatom/…/Operations/Internal/Pipeline/OperationRunner.php:17–97,130–149`, `Operations/Pipeline/OperationContext.php:13–79`.

Chatom пишет outbox на connection space; outbox consumers выполняют fanout по стабильному event ID (`Outbox/OutboxWriter.php:39–44,60–103`). Vaulter тоже имеет отдельный stage/relay pattern (`vaulter/…/Webhooks/OutboxRecorder.php:17–32,129–180`). Это реализованные локальные механизмы, но данный обзор не проверял их под конкурентной нагрузкой и не доказывает exactly-once delivery.

AzGuard проводит mutation на одном writer connection, первым блокирует `panel_state`, заново планирует retry и доставляет events после root commit. `AuthorityTransaction` проверяет root transaction, PDO и Fiber (`azguard/…/Changes/ChangePipeline.php:38–48`, `Storage/AuthorityTransaction.php:13–46`). `ChangeEventPublisher` после commit сообщает об ошибке listener и продолжает (`Changes/ChangeEventPublisher.php:56–68`). **После-commit callback сам по себе не даёт durable delivery после падения процесса**; это не эквивалент outbox.

**Предложение Commerz.** Одна точка mutation для reserve/release/commit stock, redeem coupon, place order, apply payment notification. Стабильный idempotency key должен связываться с scope и fingerprint запроса; повтор с другим содержимым — конфликт. Plugin calculations желательно возвращают typed results, а owner операции применяет запись с проверкой инвариантов. Сетевой payment call требует отдельной durable state machine; database rollback не откатывает платёж у провайдера. Integration events — outbox в той же БД/транзакции, consumer dedupe и версионированный envelope.

**Не копировать автоматически.** Блокировка одного `panel_state` на все изменения tenant/store создаст в commerce общую очередь для независимых SKU. Гранулярность inventory lock должна следовать резервируемому ресурсу и алгоритму allocation; ordering locks по нескольким SKU нужно доказать тестами. Не следует переносить все девять фаз Chatom в любую простую команду. Общая структура полезна, конкретные anchors и обязательные фазы должны возникать из commerce сценариев.

## 9. Extension points должны быть проверяемыми, а ошибки — стабильными

**Реализовано.**

- Chatom публикует machine error code и category, контекст исключения ограничен scalar diagnostics без секретных ключей (`Errors/Exceptions/ChatomException.php:14–44`). AzGuard имеет общий `AzGuardException::code()` (`Exceptions/AzGuardException.php:9–14`). Vaulter базовая ошибка умеет HTTP rendering (`Exceptions/VaulterException.php:13–30`): transport separation у соседей различается.
- Chatom architecture tests проверяют package dependencies, internal imports, foreign-table writes; предусмотрены negative fixtures, чтобы scanner не проходил пустым утверждением (`tests/Arch/ArchitectureGatesTest.php:70–109`).
- AzGuard предоставляет reusable `PluginContractTests`: stable ID, чистая panel, две panel, отключённый plugin без остаточного эффекта, boot не мутирует frozen panel (`Testing/Contracts/PluginContractTests.php:14–23,68–103`). Есть отдельные engine tests, включая race/deadlock/replica/isolation. Они найдены в tracked files, в этом исследовании не запускались.
- Во всех трёх root Composer scripts содержат Pest, Pint, PHPStan и Rector; детали разнятся. Наличие общего набора инструментов не означает одинаковые thresholds или одинаковое качество всех модулей.

**Предложение Commerz.** Минимальный testkit для автора plugin важнее декларации «можно расширить всё»: один дополнительный attribute type, один discount, один payment adapter и один inventory policy должны проходить consumer tests вне namespaces core. Проверять install без optional modules, config cache, две независимые contexts в одном worker, duplicate registration, schema upgrade и отключение contribution, на которую ссылается сохранённый документ.

Для реального commerce поведения нужны конкурентные проверки oversell, double reservation, double coupon redemption, повторного webhook и retry после неизвестного результата внешнего payment. Arithmetic tests должны проверять rounding и распределение суммы между строками. Engine-specific guarantees должны проверяться на заявленных production DB. SQLite тест не доказывает row-locking semantics PostgreSQL/MySQL.

**Не копировать автоматически.** Vaulter прямо документирует отличия SQLite constraints и ограниченную MariaDB квалификацию (`docs/guide/database-compatibility.md:30–47`). Поэтому поддержка четырёх драйверов в config enum не равна одинаковой сохранности данных. До собственной CI matrix Commerz нельзя заявлять DB parity. Проценты coverage/mutation из соседнего composer не являются готовой стратегией тестирования.

## 10. Конкретные точки расширения и integration seams для следующего прототипа

Ниже **предложения**, не существующие интерфейсы Commerz. Их окончательные имена и сигнатуры нужно проверить на двух реально разных implementations. Generic framework ради единственной implementation вводить не требуется.

| Задача | Кандидат интерфейса / declaration | Инвариант, который остаётся у core | Проверка вариативности |
| --- | --- | --- | --- |
| Подключение host identity | `ActorResolver`, `CustomerReferenceResolver`, explicit `CommerceContext` | scope/identity проверяется каждым входом операции | guest customer / registered B2B buyer |
| Тип товара | `ProductTypeDefinition`, специализированные fulfillment capabilities | variant identity, validation и жизненный цикл ссылок | physical / digital; bundle позже отдельным сценарием |
| Свойства | `AttributeType`, `AttributeCodec`, query capability description | согласованность value type/cardinality/version и scope | decimal measurement / localized option |
| Расчёт цены | `PriceResolver`, `PriceQuote` | currency, quantity, quote version и корректность arithmetic | fixed price list / customer contract price |
| Промо | `PromotionCondition`, `PromotionEffect`, `PromotionCombinationPolicy` | money allocation и atomic redemption quotas | coupon / automatic cart discount |
| Остатки | `InventoryAllocationPolicy` | reserve/release atomicity, ledger и available balance | single warehouse / split allocation |
| Платежи | `PaymentGateway`, typed capability declarations | payment intent state, dedupe и переходы по подтверждениям | redirect gateway / authorize-capture gateway |
| Доставка | `ShippingQuoteProvider`, `FulfillmentAdapter` | immutable selected quote и fulfillment state | fixed tariff / carrier API |
| Media | `ProductMediaStore` или resource-reference seam | product association и ordering принадлежат commerce | basic Laravel storage / Vaulter bridge |
| Авторизация | narrow authorization port или Laravel Gate adapter | tenant и product/order ownership всегда внутри core | host policies / AzGuard bridge |
| События | `IntegrationEventSink`, outbox consumer contract | stable event ID, payload schema и commit atomicity | webhook / integration job |
| Обслуживание plugin | schema contribution, doctor, projection rebuilder, retention hook | ownership, upgrade ordering, сохранность исторических references | install/disable/upgrade/remove с имеющимися заказами |

**Optional bridges.** `commerz-azguard` может переводить commerce actions в AzGuard permissions/scopes, `commerz-vaulter` — работать с файлами товара через публичные контракты Vaulter, `commerz-filament` — отображать definitions без привязки core к Filament. Chatom integration для customer support/заказных обсуждений должна быть опциональной event consumer; нет причины делать Chatom dependency ядра commerce.

**Обнаруженный integration drift.** `vaulter/packages/azgard/composer.json:20–26` требует `axioma-studio/azguard-core` и `axioma-studio/azguard-context`; текущий соседний core называется `axiomasoft/azguard`. Комментарий bridge provider упоминает старые конфигурации/traits (`VaulterAzgardServiceProvider.php:15–30`). Это доказанное несоответствие деклараций, но не результат запуска Composer и не доказательство, что bridge всегда неработоспособен: может существовать отдельная совместимая историческая линия. При проектировании нового bridge нужно ориентироваться на текущую публичную поверхность и consumer fixture, а не копировать старые imports.

### Что передать в основное проектирование

Принять как сильные локальные ориентиры: короткий configurable prefix; единый table resolver; раздельные internal/host IDs; typed immutable definitions; чёткие API/SPI/Internal; compile/freeze registry с provenance; schema ownership; command + transaction + outbox; consumer contract tests; optional bridges.

Оставить открытыми до commerce сценариев: physical tenant isolation, перечень Composer packages, ID default, точный plugin lifecycle, поддерживаемые DB engines, модель typed attributes и search projections, точность/rounding денег, складские единицы измерения, порядок discount/tax calculations, granularity reservation locks и границы транзакции checkout.

Проверяемые первые эксперименты: (1) пользователь добавляет decimal attribute с unit и filter; (2) два plugins конкурируют за coupon effect и получают объяснимый conflict; (3) два покупателя пытаются зарезервировать последнюю единицу; (4) платежный webhook приходит дважды; (5) один worker последовательно обслуживает два tenant; (6) optional media/auth bridge отсутствует при чистой установке core. Успех этих сценариев даст больше оснований для интерфейсов, чем максимальное число extension hooks.
