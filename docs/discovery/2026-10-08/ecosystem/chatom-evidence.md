# chatom — source evidence

HEAD `8d6b2ac050955ed91696c92b2d364c3a53629eb3`; dirty: `False`; working-tree snapshot.

## AGENTS.md

Source: [chatom/AGENTS.md](/home/vostrikov/projects/packages/chatom/AGENTS.md); SHA-256 `8063238275a820830d84aa488fee7b60d3bcc559e58e8ef1f7fecc4515b6c180`.

Lines 1–26:

```text
1: <!-- generated from .ai/guidelines/agent-entrypoint.md by scripts/build-agent-docs.sh -->
2: 
3: # Chatom
4: 
5: - Edit this context only in `.ai/guidelines/agent-entrypoint.md`, then run
6:   `bash scripts/build-agent-docs.sh`.
7: - This is a Laravel package monorepo with TypeScript clients. Keep the PHP core
8:   transport-agnostic and preserve the boundaries in `docs/architecture/`.
9: - Put PHP package code under `packages/<name>/`, PHP tests under the existing
10:   `tests/` suites or package-owned `packages/<name>/tests/`; keep TypeScript clients
11:   under `packages/<name>/js` and applications under `apps/`.
12: - Treat public contracts, wire formats, migrations, package dependencies, and
13:   exported client APIs as SemVer surfaces.
14: - Keep dependencies pointing toward the core. Feature packages may use only its
15:   public contracts and declared extension points; packages do not write to tables
16:   owned by another package.
17: - For plan work, start at `plans/ACTIVE.md`; otherwise open only the documents
18:   needed for the task.
19: - Preserve unrelated worktree changes. Validate the affected scope with the
20:   relevant Composer checks (`lint:check`, `analyse`, `refactor:check`, `test`)
21:   and client package checks where applicable.
22: - For large tasks, prioritize implementation with lightweight affected checks.
23:   Defer independent review to a separate owner-requested session; do not add
24:   SKM review loops or repeat successful checks solely for workflow ceremony.
25:   Expand validation only for a concrete risk or failure. The owner's instruction
26:   takes precedence over skill and plan review defaults.
```

## packages/core/composer.json

Source: [chatom/packages/core/composer.json](/home/vostrikov/projects/packages/chatom/packages/core/composer.json); SHA-256 `936a15debf67131e56f028ca057e54c5c2a3e96363b6bb08cf8ec66674c468ab`.

Lines 1–100:

```text
1: {
2:     "name": "axiomasoft/chatom",
3:     "description": "Embeddable, transport-agnostic Laravel chat domain core \u2014 spaces, channels, members, messages, swappable drivers.",
4:     "type": "library",
5:     "license": "MIT",
6:     "keywords": [
7:         "laravel",
8:         "chat",
9:         "messaging",
10:         "channels",
11:         "realtime",
12:         "chatom"
13:     ],
14:     "homepage": "https://github.com/axiomasoft/chatom",
15:     "authors": [
16:         {
17:             "name": "Dmitry Vostrikov",
18:             "email": "dv.vostrikov@gmail.com"
19:         }
20:     ],
21:     "support": {
22:         "issues": "https://github.com/axiomasoft/chatom/issues",
23:         "source": "https://github.com/axiomasoft/chatom"
24:     },
25:     "require": {
26:         "php": "^8.4",
27:         "illuminate/console": "^12.0|^13.0",
28:         "illuminate/contracts": "^12.0|^13.0",
29:         "illuminate/database": "^12.0|^13.0",
30:         "illuminate/http": "^12.0|^13.0",
31:         "illuminate/support": "^12.0|^13.0",
32:         "illuminate/bus": "^12.0|^13.0",
33:         "illuminate/filesystem": "^12.0|^13.0",
34:         "illuminate/pagination": "^12.0|^13.0",
35:         "illuminate/routing": "^12.0|^13.0",
36:         "illuminate/collections": "^12.0|^13.0",
37:         "illuminate/validation": "^12.0|^13.0",
38:         "laravel/framework": "^12.0|^13.0",
39:         "ext-mbstring": "*",
40:         "nesbot/carbon": "^3.0",
41:         "ramsey/uuid": "^4.9",
42:         "symfony/http-foundation": "^7.0|^8.1",
43:         "illuminate/container": "^12.0|^13.0",
44:         "ext-filter": "*",
45:         "symfony/console": "^7.0|^8.0",
46:         "illuminate/auth": "^12.0|^13.0",
47:         "symfony/http-kernel": "^7.0|^8.1"
48:     },
49:     "autoload": {
50:         "psr-4": {
51:             "Chatom\\": "src/"
52:         }
53:     },
54:     "suggest": {
55:         "axiomasoft/chatom-search": "Database message search. Without axiomasoft/chatom-search the search driver stays null.",
56:         "ext-zend-opcache": "Caches compiled PHP definitions; supported without this extension."
57:     },
58:     "extra": {
59:         "laravel": {
60:             "providers": [
61:                 "Chatom\\ChatomServiceProvider"
62:             ]
63:         },
64:         "chatom": {
65:             "owns": {
66:                 "namespaces": [
67:                     "Chatom\\"
68:                 ],
69:                 "tables": [
70:                     "chatom_audit_records",
71:                     "chatom_channels",
72:                     "chatom_members",
73:                     "chatom_mentions",
74:                     "chatom_messages",
75:                     "chatom_pins",
76:                     "chatom_reactions",
77:                     "chatom_receipts",
78:                     "chatom_space_state",
79:                     "chatom_outbox"
80:                 ],
81:                 "config": [
82:                     "chatom"
83:                 ],
84:                 "publish-tags": [
85:                     "chatom-config"
86:                 ],
87:                 "commands": [
88:                     "chatom:install",
89:                     "chatom:make-extension",
90:                     "chatom:make-space",
91:                     "chatom:nodes:upgrade",
92:                     "chatom:schema:sync",
93:                     "chatom:schema:status",
94:                     "chatom:schema:doctor",
95:                     "chatom:schema:repair",
96:                     "chatom:schema:uninstall",
97:                     "chatom:prune",
98:                     "chatom:doctor",
99:                     "chatom:repair",
100:                     "chatom:restore-rehearse",
```

## packages/core/config/chatom.php

Source: [chatom/packages/core/config/chatom.php](/home/vostrikov/projects/packages/chatom/packages/core/config/chatom.php); SHA-256 `3330724ae8e492bfc10631aeb69d23fadecd6ee16f073b13083f29358f5a326c`.

Lines 25–77:

```text
25:     | Actor types and request authentication
26:     |--------------------------------------------------------------------------
27:     | Each provider owns one stable actor type. The built-in `user` provider uses
28:     | the configured model and Laravel's authenticated user. Applications may add
29:     | providers for bots or other actor tables; ids may overlap across types.
30:     */
31:     'actors' => [
32:         'providers' => [],
33:         'user_model' => env('CHATOM_USER_MODEL'),
34:     ],
35: 
36:     /*
37:     |--------------------------------------------------------------------------
38:     | Base (default-space) table names — shared schema (D12)
39:     |--------------------------------------------------------------------------
40:     | Migrations read these in runtime; spaces override per-entity via map (D3).
41:     */
42:     'table_names' => [],
43: 
44:     /*
45:     |--------------------------------------------------------------------------
46:     | Default-space Eloquent models — swappable per entity (D3)
47:     |--------------------------------------------------------------------------
48:     | Relations resolve the related class through this map, so an application
49:     | may extend/replace a model without forking the package. List only the
50:     | entities you replace, e.g. 'message' => App\Models\ChatMessage::class;
51:     | unlisted entities keep the package's built-in models.
52:     */
53:     'models' => [
54:     ],
55: 
56:     /*
57:     |--------------------------------------------------------------------------
58:     | Primary key strategy (default; per-space override at generation time)
59:     |--------------------------------------------------------------------------
60:     | uuid7 (recommended, time-sortable) | ulid | bigint
61:     */
62:     'ids' => [
63:         'default' => env('CHATOM_ID_STRATEGY', 'uuid7'),
64: 
65:     ],
66: 
67:     /*
68:     |--------------------------------------------------------------------------
69:     | Swappable drivers (resolved by ChatManager)
70:     |--------------------------------------------------------------------------
71:     */
72:     'drivers' => [
73:         'broadcast' => env('CHATOM_BROADCAST', 'null'),     // echo|redis from chatom-broadcasting, else null
74:         'storage' => env('CHATOM_STORAGE', 'null'),         // flysystem from chatom-attachments, else null
75:         'push' => env('CHATOM_PUSH', 'null'),               // null until a push driver is extended
76:         'search' => env('CHATOM_SEARCH', 'null'),           // database from chatom-search, else null
77:         'presence' => env('CHATOM_PRESENCE', 'array'),      // cache|redis from chatom-presence, else array
```

Lines 100–131:

```text
100:     /*
101:     |--------------------------------------------------------------------------
102:     | Spaces — list of ChatSpaceProvider FQCN (config-cache safe)
103:     |--------------------------------------------------------------------------
104:     | Compiled at boot by ConfigurationCompiler into an immutable
105:     | RuntimeDefinitions snapshot. Closures are forbidden here: they cannot
106:     | survive `php artisan config:cache`.
107:     */
108:     'spaces' => [],
109: 
110:     // ChatSpaceProvider FQCN of the default space; null keeps the built-in provider.
111:     'default_space' => null,
112: 
113:     /*
114:     |--------------------------------------------------------------------------
115:     | Generated per-space migrations
116:     |--------------------------------------------------------------------------
117:     | Output directory for SchemaMigrationFiles. Null resolves to the app's
118:     | database/migrations at generation time.
119:     */
120:     'migrations' => [
121:         'path' => null,
122:     ],
123: 
124:     /*
125:     |--------------------------------------------------------------------------
126:     | extra_data jsonb escape hatch (global default; per-space toggleable)
127:     |--------------------------------------------------------------------------
128:     */
129:     'extra_data' => [
130:         'enabled' => true,
131:     ],
```

## packages/core/src/Spaces/Space.php

Source: [chatom/packages/core/src/Spaces/Space.php](/home/vostrikov/projects/packages/chatom/packages/core/src/Spaces/Space.php); SHA-256 `78f5fe20d01a8a56c3bdf1e5f065413171adcf9164a5acb301977ff42974252d`.

Lines 305–356:

```text
305:     }
306: 
307:     /**
308:      * The explicit table prefix for this space, or null when none was set (the
309:      * space then relies on an explicit table map or the global defaults).
310:      */
311:     public function tablePrefixValue(): ?string
312:     {
313:         return $this->tablePrefix;
314:     }
315: 
316:     /**
317:      * Whether this space writes to its own per-space tables rather than the
318:      * shared default-space schema: it carries a prefix or an explicit table map.
319:      */
320:     public function hasOwnTables(): bool
321:     {
322:         return $this->ownsTables ?? ($this->tablePrefix !== null || $this->tableMap !== []);
323:     }
324: 
325:     /**
326:      * Resolve a physical table name for a base entity key: explicit map wins,
327:      * then a space prefix ("{prefix}_{base}"), else the global default table.
328:      * Frozen copies never read live config.
329:      */
330:     /**
331:      * The explicit `tables([...])` map only; materialization fills the rest of {@see table()} from the prefix or
332:      * the global names, which the schema compiler derives itself.
333:      *
334:      * @return array<string, string> logical table => physical table name
335:      */
336:     public function tableOverrides(): array
337:     {
338:         return $this->tableOverrides ?? $this->tableMap;
339:     }
340: 
341:     public function table(string $base): string
342:     {
343:         if (array_key_exists($base, $this->tableMap)) {
344:             return $this->tableMap[$base];
345:         }
346: 
347:         if ($this->tablePrefix !== null) {
348:             return "{$this->tablePrefix}_{$base}";
349:         }
350: 
351:         throw FrozenDefinitionException::forMessage(
352:             "Compiled space [{$this->id}] has no table for [{$base}].",
353:         );
354:     }
355: 
356:     /**
```

Lines 505–528:

```text
505:         $copy = clone $this;
506:         $copy->ownsTables = $this->hasOwnTables();
507:         $copy->tableOverrides = $this->id === 'default' ? [...$tableNames, ...$this->tableOverrides()] : $this->tableOverrides();
508: 
509:         if ($this->id === 'default') {
510:             $copy->tableMap = [...$tableNames, ...$copy->tableMap];
511:         }
512:         $copy->tablePrefix ??= $this->id === 'default' ? 'chatom' : null;
513:         $copy->frozen = false;
514: 
515:         foreach ($tableNames as $key => $name) {
516:             if (array_key_exists($key, $copy->tableMap)) {
517:                 continue;
518:             }
519: 
520:             if ($copy->tablePrefix !== null) {
521:                 $copy->tableMap[$key] = "{$copy->tablePrefix}_{$key}";
522: 
523:                 continue;
524:             }
525: 
526:             // A space that already owns its channels table must not inherit the
527:             // default receipt table when the global map gains `receipts`.
528:             $channels = $copy->tableMap['channels'] ?? null;
```

## packages/core/src/Schema/Data/SchemaSpace.php

Source: [chatom/packages/core/src/Schema/Data/SchemaSpace.php](/home/vostrikov/projects/packages/chatom/packages/core/src/Schema/Data/SchemaSpace.php); SHA-256 `294d0beef817079158003f05f83aaefdb7e976228c5ff27ed1e42de42bdd0cc0`.

Lines 10–78:

```text
10: /**
11:  * The storage decisions of one space that shape its schema — and nothing else. Built at compile time from the
12:  * compiled space definition; aliases are never schema spaces (they write into their owner's storage).
13:  *
14:  * Physical table of a logical name: tables[logical] ?? "{tablePrefix}_{logical}". Laravel prepends the connection's
15:  * own `prefix` in SQL, so every identifier SpaceSchema derives starts with connectionPrefix.
16:  */
17: #[Spi(kind: Spi::CALL, since: '1.0')]
18: final readonly class SchemaSpace
19: {
20:     /**
21:      * @param  array<string, string>  $tables  explicit logical => physical overrides
22:      */
23:     public function __construct(
24:         public string $id,
25:         public bool $isDefault,
26:         public string $connection,
27:         public string $connectionPrefix,
28:         public IdStrategy $idStrategy,
29:         public string $tablePrefix,
30:         public array $tables = [],
31:     ) {}
32: 
33:     /** Physical name as the application sees it (Laravel adds the connection prefix). */
34:     public function table(string $logical): string
35:     {
36:         return $this->tables[$logical] ?? $this->tablePrefix.'_'.$logical;
37:     }
38: 
39:     /** Physical name as the database sees it. */
40:     public function databaseTable(string $logical): string
41:     {
42:         return $this->connectionPrefix.$this->table($logical);
43:     }
44: 
45:     /**
46:      * The space_state row (without timestamps).
47:      *
48:      * @return array{space: string, id_strategy: string, table_prefix: string, table_map: string|null, fingerprint: string}
49:      */
50:     public function stateRow(): array
51:     {
52:         $tables = $this->tables;
53:         ksort($tables);
54: 
55:         return [
56:             'space' => $this->id,
57:             'id_strategy' => $this->idStrategy->value,
58:             'table_prefix' => $this->tablePrefix,
59:             'table_map' => $tables === [] ? null : json_encode($tables, JSON_THROW_ON_ERROR),
60:             'fingerprint' => $this->fingerprint(),
61:         ];
62:     }
63: 
64:     /**
65:      * sha256 of the decisions that must never change after the first migrate: id strategy, prefix and overrides.
66:      * The connection is deliberately excluded — moving a database to another host is not a schema change.
67:      */
68:     public function fingerprint(): string
69:     {
70:         $tables = $this->tables;
71:         ksort($tables);
72: 
73:         return hash('sha256', json_encode([
74:             'id_strategy' => $this->idStrategy->value,
75:             'table_prefix' => $this->tablePrefix,
76:             'tables' => $tables,
77:         ], JSON_THROW_ON_ERROR));
78:     }
```

## packages/core/src/Schema/Enums/IdStrategy.php

Source: [chatom/packages/core/src/Schema/Enums/IdStrategy.php](/home/vostrikov/projects/packages/chatom/packages/core/src/Schema/Enums/IdStrategy.php); SHA-256 `0639a5904ae6a657958dbe55de5f8c7892e2860207a9502017686bed2cbf0df8`.

Lines 9–29:

```text
9: /**
10:  * Key type of a space's own rows. A closed set: the column shape of each case is frozen in SpaceSchema, so a
11:  * released migration always produces the same DDL. Chosen per space and immutable after its first migrate.
12:  */
13: #[Api(since: '1.0')]
14: enum IdStrategy: string
15: {
16:     case Uuid7 = 'uuid7';
17:     case Ulid = 'ulid';
18:     case Bigint = 'bigint';
19: 
20:     /** Eloquent key type of a model on a space with this strategy. */
21:     public function keyType(): string
22:     {
23:         return $this === self::Bigint ? 'int' : 'string';
24:     }
25: 
26:     public function incrementing(): bool
27:     {
28:         return $this === self::Bigint;
29:     }
```

## packages/core/src/Schema/Internal/Services/SchemaNames.php

Source: [chatom/packages/core/src/Schema/Internal/Services/SchemaNames.php](/home/vostrikov/projects/packages/chatom/packages/core/src/Schema/Internal/Services/SchemaNames.php); SHA-256 `ec36aa0fc9fedde101954e127d1ffbb33329ba0ebd481a9c2c5bf1bd120e50eb`.

Lines 9–39:

```text
9: /**
10:  * Deterministic names: database identifiers, space slugs, step ids and migration names. Pure; frozen — a released
11:  * migration was generated with these rules, so changing any of them is a MAJOR change.
12:  */
13: final class SchemaNames
14: {
15:     /** PostgreSQL keeps 63 bytes of an identifier (NAMEDATALEN - 1); MySQL allows 64 characters. */
16:     public const int MAX_IDENTIFIER = 63;
17: 
18:     public const string STEP_ID = '/^(\d{4}_\d{2}_\d{2}_\d{6})_([a-z][a-z0-9_]{0,63})$/';
19: 
20:     public const string COMPONENT_ID = '/^[a-z][a-z0-9_]{0,23}$/';
21: 
22:     public const string LOGICAL_TABLE = '/^[a-z][a-z0-9_]{0,39}$/';
23: 
24:     public const string IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';
25: 
26:     /**
27:      * Name of an index, constraint, function or trigger of a table: "{databaseTable}_{suffix}", or, when longer than
28:      * 63 bytes, its first 54 bytes + '_' + the first 8 hex digits of its sha256 (63 bytes in total).
29:      */
30:     public static function identifier(string $databaseTable, string $suffix): string
31:     {
32:         $full = $databaseTable.'_'.$suffix;
33: 
34:         if (strlen($full) <= self::MAX_IDENTIFIER) {
35:             return $full;
36:         }
37: 
38:         return substr($full, 0, self::MAX_IDENTIFIER - 9).'_'.substr(hash('sha256', $full), 0, 8);
39:     }
```

## packages/core/src/Schema/Contracts/SchemaComponent.php

Source: [chatom/packages/core/src/Schema/Contracts/SchemaComponent.php](/home/vostrikov/projects/packages/chatom/packages/core/src/Schema/Contracts/SchemaComponent.php); SHA-256 `34f3f1d550342ce6bda14791f6db976344369b10143c9d206972ff5de701a8b5`.

Lines 10–41:

```text
10: /**
11:  * The schema a package contributes to every space it applies to: an append-only, ordered list of immutable steps.
12:  *
13:  * Step ids are `YYYY_MM_DD_HHMMSS_<snake_name>`. The timestamp orders the generated migration files, so a step that
14:  * needs objects of another component carries a later timestamp than the step that creates them. A released step is
15:  * never edited, reordered or removed — the schema changes only by appending a new step.
16:  *
17:  * tables() lists every logical table the steps create; a step may create or alter only these, and may reference
18:  * (foreign key) only these and the tables of components in dependsOn(). Logical names are space-relative
19:  * (`messages`, `poll_votes`); the space maps them to physical names.
20:  */
21: #[Spi(kind: Spi::IMPLEMENT, since: '1.0')]
22: interface SchemaComponent
23: {
24:     /** Stable id, `^[a-z][a-z0-9_]{0,23}$`: the package slug, e.g. 'core'. Part of every migration name — never renamed. */
25:     public function id(): string;
26: 
27:     /** @return list<string> logical table names created by this component, `^[a-z][a-z0-9_]{0,39}$` */
28:     public function tables(): array;
29: 
30:     /** @return array<string, class-string<SchemaStep>> step id => step class, in execution order */
31:     public function steps(): array;
32: 
33:     /** @return list<string> ids of components whose tables this component references */
34:     public function dependsOn(): array;
35: 
36:     /**
37:      * Whether the component's tables exist in this space. Decided at compile time; must be a pure function of the
38:      * given space (never of runtime state), because the answer selects migration files.
39:      */
40:     public function appliesTo(SchemaSpace $space): bool;
41: }
```

## packages/core/src/Schema/Contracts/SchemaStep.php

Source: [chatom/packages/core/src/Schema/Contracts/SchemaStep.php](/home/vostrikov/projects/packages/chatom/packages/core/src/Schema/Contracts/SchemaStep.php); SHA-256 `2f25cba11c67af94f7d481515d662cade44edc8248e06031a5d5a6b546bf6e5b`.

Lines 10–25:

```text
10: /**
11:  * One immutable schema change of one component, applied to one space.
12:  *
13:  * A step reads nothing but the SpaceSchema it is given: no config(), no container, no feature flags, no shared helper
14:  * whose output may change later (copy the code into the step instead). Its output is therefore a pure function of the
15:  * space's storage decisions, which are frozen after the first migrate (space_state).
16:  *
17:  * down() exists for development and tests (rollback, uninstall); production recovers forward.
18:  */
19: #[Spi(kind: Spi::IMPLEMENT, since: '1.0')]
20: interface SchemaStep
21: {
22:     public function up(SpaceSchema $schema): void;
23: 
24:     public function down(SpaceSchema $schema): void;
25: }
```

## packages/core/src/Plugins/Contracts/Plugin.php

Source: [chatom/packages/core/src/Plugins/Contracts/Plugin.php](/home/vostrikov/projects/packages/chatom/packages/core/src/Plugins/Contracts/Plugin.php); SHA-256 `68b0e6e578b223fd62dcce4501a288626628a2081d3649eef3a6450cc256538d`.

Lines 11–21:

```text
11: /**
12:  * A chatom plugin: pure declarations, no I/O, no container lookups, no closures. Core instantiates the class with
13:  * `new $class()` while compiling the plugin graph and never while serving a request from the definitions cache.
14:  */
15: #[Spi(kind: Spi::IMPLEMENT, since: '1.0')]
16: interface Plugin
17: {
18:     public function manifest(): PluginManifest;
19: 
20:     public function register(PluginRegistrar $registrar): void;
21: }
```

## packages/core/src/Plugins/PluginManifest.php

Source: [chatom/packages/core/src/Plugins/PluginManifest.php](/home/vostrikov/projects/packages/chatom/packages/core/src/Plugins/PluginManifest.php); SHA-256 `a2a06153b359b27eace4949095f9db6c75037a5434b54bf5744680d8a16af066`.

Lines 10–38:

```text
10: /**
11:  * Identity and dependencies of a plugin. Versions are not declared here: Composer is the version authority.
12:  */
13: #[Spi(kind: Spi::CALL, since: '1.0')]
14: final readonly class PluginManifest
15: {
16:     public const string ID_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\.[a-z0-9]+(?:-[a-z0-9]+)*)+$/';
17: 
18:     private const string PACKAGE_PATTERN = '/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/';
19: 
20:     /** @var list<string> */
21:     public const array DATABASES = ['pgsql', 'mysql', 'mariadb', 'sqlite'];
22: 
23:     /**
24:      * @param  string  $id  e.g. 'chatom.voice-message', 'acme.badge'
25:      * @param  string  $package  Composer name, e.g. 'axiomasoft/chatom-voice-message'
26:      * @param  list<string>  $requires  plugin ids that must be registered and enabled
27:      * @param  list<string>  $optional  plugin ids integrated when present (ordering only)
28:      * @param  list<string>  $databases  'pgsql', 'mysql', 'mariadb', 'sqlite'; empty = every supported driver
29:      *
30:      * @throws InvalidPluginException
31:      */
32:     public function __construct(
33:         public string $id,
34:         public string $package,
35:         public array $requires = [],
36:         public array $optional = [],
37:         public array $databases = [],
38:     ) {
```

## packages/core/src/Plugins/PluginRegistrar.php

Source: [chatom/packages/core/src/Plugins/PluginRegistrar.php](/home/vostrikov/projects/packages/chatom/packages/core/src/Plugins/PluginRegistrar.php); SHA-256 `430cbccc11586a07d7580390fe69ffc55d09783f886b69e9793168ef3b56acc6`.

Lines 30–82:

```text
30:  * compiled graph survives the definitions cache round trip. The graph compiler validates everything at once.
31:  *
32:  * @phpstan-type Declarations array{
33:  * config?: list<class-string<ConfigSection>>, schema?: list<class-string<SchemaComponent>>,
34:  * stages?: list<StageDefinition>, operations?: list<OperationDefinition>,
35:  * channelTypes?: list<array{string, ChannelTypeDefinition}>, spaces?: list<class-string<ChatSpaceProvider>>,
36:  * capabilities?: list<CapabilityDefinition>, drivers?: list<array{string, string, class-string<DriverFactory>, bool}>,
37:  * routes?: list<string>, commands?: list<class-string<Command>>, rebuilders?: list<class-string<ProjectionRebuilder>>,
38:  * healthChecks?: list<class-string<HealthCheck>>, pruners?: list<class-string<RetentionPruner>>,
39:  * purgeMembers?: list<class-string<MessagePurgeMember>>, outboxConsumers?: list<class-string<OutboxConsumer>>,
40:  * messageContributors?: list<class-string<MessageContributor>>, spaceEntities?: list<array{string, string, class-string<Model>}>,
41:  * models?: list<array{string, class-string<Model>}>, agents?: list<AgentDefinition>, nodes?: list<NodeDefinition>,
42:  * eventFamilies?: list<EventFamily>, telemetryLabels?: list<array{string, list<string>}>
43:  * }
44:  */
45: #[Spi(kind: Spi::CALL, since: '1.0')]
46: final class PluginRegistrar
47: {
48:     /** @var Declarations */
49:     private array $declarations = [];
50: 
51:     public function __construct(public readonly string $plugin) {}
52: 
53:     /** @param class-string<ConfigSection> ...$sections */
54:     public function config(string ...$sections): self
55:     {
56:         foreach ($sections as $value) {
57:             $this->declarations['config'][] = $value;
58:         }
59: 
60:         return $this;
61:     }
62: 
63:     /** @param class-string<SchemaComponent> $component  built with the compiled config sections (`sections` argument) */
64:     public function schema(string $component): self
65:     {
66:         $this->declarations['schema'][] = $component;
67: 
68:         return $this;
69:     }
70: 
71:     public function stage(StageDefinition $stage): self
72:     {
73:         $this->declarations['stages'][] = $stage;
74: 
75:         return $this;
76:     }
77: 
78:     public function operation(OperationDefinition $operation): self
79:     {
80:         $this->declarations['operations'][] = $operation;
81: 
82:         return $this;
```

Lines 138–175:

```text
138:     /** @param class-string<ProjectionRebuilder> $rebuilder */
139:     public function rebuilder(string $rebuilder): self
140:     {
141:         $this->declarations['rebuilders'][] = $rebuilder;
142: 
143:         return $this;
144:     }
145: 
146:     /** @param class-string<HealthCheck> $check */
147:     public function healthCheck(string $check): self
148:     {
149:         $this->declarations['healthChecks'][] = $check;
150: 
151:         return $this;
152:     }
153: 
154:     /** @param class-string<RetentionPruner> $pruner */
155:     public function pruner(string $pruner): self
156:     {
157:         $this->declarations['pruners'][] = $pruner;
158: 
159:         return $this;
160:     }
161: 
162:     /** @param class-string<MessagePurgeMember> $member */
163:     public function purgeMember(string $member): self
164:     {
165:         $this->declarations['purgeMembers'][] = $member;
166: 
167:         return $this;
168:     }
169: 
170:     /** @param class-string<OutboxConsumer> $consumer */
171:     public function outboxConsumer(string $consumer): self
172:     {
173:         $this->declarations['outboxConsumers'][] = $consumer;
174: 
175:         return $this;
```

Lines 186–225:

```text
186:     /**
187:      * Default model of a space entity ('badge', 'badge_award', …), scaffolded by `chatom:make-space` as
188:      * "<Space><tableSuffix>"; an explicit `chatom.models.<key>` of the host wins.
189:      *
190:      * @param  class-string<Model>  $model
191:      */
192:     public function spaceEntity(string $key, string $tableSuffix, string $model): self
193:     {
194:         $this->declarations['spaceEntities'][] = [$key, $tableSuffix, $model];
195: 
196:         return $this;
197:     }
198: 
199:     /**
200:      * Default model of `chatom.models.<key>` for an entity the plugin owns ('badge', …); an explicit
201:      * `chatom.models.<key>` of the host wins. The scaffold base model of `chatom:make-space` is spaceEntity().
202:      *
203:      * @param  class-string<Model>  $model
204:      */
205:     public function model(string $key, string $model): self
206:     {
207:         $this->declarations['models'][] = [$key, $model];
208: 
209:         return $this;
210:     }
211: 
212:     /** A versioned document node: its codec, upgrade chain and interaction policy (PLUGIN_DUPLICATE_CONTRIBUTION on a taken slug). */
213:     public function agent(AgentDefinition $agent): self
214:     {
215:         $this->declarations['agents'][] = $agent;
216: 
217:         return $this;
218:     }
219: 
220:     public function node(NodeDefinition $node): self
221:     {
222:         $this->declarations['nodes'][] = $node;
223: 
224:         return $this;
225:     }
```

## packages/core/src/Plugins/Internal/PluginGraphCompiler.php

Source: [chatom/packages/core/src/Plugins/Internal/PluginGraphCompiler.php](/home/vostrikov/projects/packages/chatom/packages/core/src/Plugins/Internal/PluginGraphCompiler.php); SHA-256 `e1406f02f16da9f29500fcd49fc3075d16387c8ede099683e13fe449ede01d98`.

Lines 42–79:

```text
42: /**
43:  * Compiles the registered plugins into one CompiledPluginGraph:
44:  *
45:  *   1. instantiate each class with `new $class()` and collect manifest + registrar declarations;
46:  *   2. validate ids, disabled ids, requires/optional edges, namespaces and contribution keys — every problem
47:  *      of every plugin is reported in one InvalidPluginGraphException, sorted;
48:  *   3. order the enabled plugins topologically (requires ∪ present optional), ties broken by id;
49:  *   4. merge their contributions in that order.
50:  *
51:  * No container or config access; checks schema files in installed package roots.
52:  * The registration order never matters.
53:  *
54:  * @phpstan-import-type Declarations from PluginRegistrar
55:  */
56: final class PluginGraphCompiler
57: {
58:     /** @var list<string> */
59:     public const array DRIVER_TYPES = ['storage', 'push', 'search', 'presence', 'broadcast'];
60: 
61:     /** Core contributions compiled into every graph; not a plugin. @var list<class-string<SchemaComponent>> */
62:     public const array CORE_SCHEMA_COMPONENTS = [CoreSchemaComponent::class, AuditSchemaComponent::class];
63: 
64:     /** `vendor.kind`, the slug of a document node. */
65:     private const string NODE_SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*\.[a-z0-9]+(?:-[a-z0-9]+)*$/';
66: 
67:     /** Class-string contributions: registrar kind => required parent type. */
68:     private const array CLASS_KINDS = [
69:         'config' => ConfigSection::class,
70:         'schema' => SchemaComponent::class,
71:         'spaces' => ChatSpaceProvider::class,
72:         'commands' => Command::class,
73:         'rebuilders' => ProjectionRebuilder::class,
74:         'healthChecks' => HealthCheck::class,
75:         'pruners' => RetentionPruner::class,
76:         'purgeMembers' => MessagePurgeMember::class,
77:         'outboxConsumers' => OutboxConsumer::class,
78:         'messageContributors' => MessageContributor::class,
79:     ];
```

Lines 101–128:

```text
101:     public function compile(array $plugins, array $disabled = [], string $key = ''): CompiledPluginGraph
102:     {
103:         $this->problems = [];
104:         $nodes = $this->nodes($plugins);
105:         $disabled = array_values(array_unique($disabled));
106:         sort($disabled, SORT_STRING);
107: 
108:         foreach ($disabled as $id) {
109:             if (! isset($nodes[$id])) {
110:                 $this->problems[] = "PLUGIN_UNKNOWN_DISABLED {$id}";
111:             }
112:         }
113: 
114:         $enabled = array_diff_key($nodes, array_flip($disabled));
115:         $dependencies = $this->dependencies($nodes, $enabled, $disabled);
116:         $order = $this->order($dependencies);
117: 
118:         foreach ($enabled as $id => $node) {
119:             $this->checkContributions($id, $node);
120:         }
121: 
122:         $graph = $this->merge($key, $order, $enabled, $disabled);
123: 
124:         if ($this->problems !== []) {
125:             throw InvalidPluginGraphException::fromProblems($this->problems);
126:         }
127: 
128:         return $graph;
```

## packages/core/src/Actors/Data/ActorRef.php

Source: [chatom/packages/core/src/Actors/Data/ActorRef.php](/home/vostrikov/projects/packages/chatom/packages/core/src/Actors/Data/ActorRef.php); SHA-256 `736c776f10e7974a925cc72ecfe6be74d04e5a9e8545d21f2f8f67b598cdb502`.

Lines 11–31:

```text
11: #[Api(since: '1.0')]
12: final readonly class ActorRef implements Stringable
13: {
14:     private const string TYPE_PATTERN = '/^[a-z][a-z0-9_-]{0,31}$/';
15: 
16:     private function __construct(public string $type, public string $id) {}
17: 
18:     public static function of(string $type, string|int $id): self
19:     {
20:         $id = (string) $id;
21: 
22:         if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
23:             throw InvalidCommandException::field(self::class, 'type', 'must be a stable lowercase alias');
24:         }
25: 
26:         if ($id === '' || strlen($id) > 64) {
27:             throw InvalidCommandException::field(self::class, 'id', 'must be 1..64 bytes');
28:         }
29: 
30:         return new self(type: $type, id: $id);
31:     }
```

## packages/core/src/Messages/Data/SendMessage.php

Source: [chatom/packages/core/src/Messages/Data/SendMessage.php](/home/vostrikov/projects/packages/chatom/packages/core/src/Messages/Data/SendMessage.php); SHA-256 `0503089a1291b4944bc9af93cf91279e9a2ed75e65ccd9c92e57720da72981e7`.

Lines 15–38:

```text
15: #[Api(since: '1.0')]
16: final readonly class SendMessage
17: {
18:     /**
19:      * @param  array<string, mixed>  $extraData
20:      * @param  array<string, list<object>>  $contributions
21:      */
22:     public function __construct(
23:         public ChannelId $channel,
24:         public ActorRef $sender,
25:         public MessageDocument $content,
26:         public ?SpaceId $space = null,
27:         public ?string $clientMessageId = null,
28:         public bool $silent = false,
29:         public ?MessageId $replyTo = null,
30:         public ?MessageId $parent = null,
31:         public MessageType $type = MessageType::Regular,
32:         public array $extraData = [],
33:         public array $contributions = [],
34:     ) {
35:         if ($clientMessageId !== null && ($clientMessageId === '' || strlen($clientMessageId) > 128)) {
36:             throw InvalidCommandException::field(self::class, 'clientMessageId', 'must be 1..128 bytes');
37:         }
38:     }
```

## packages/core/src/Operations/Pipeline/OperationContext.php

Source: [chatom/packages/core/src/Operations/Pipeline/OperationContext.php](/home/vostrikov/projects/packages/chatom/packages/core/src/Operations/Pipeline/OperationContext.php); SHA-256 `f835d0812de380e518c0712992c2dd6f142768feb06645dbfee6a4d481b7a032`.

Lines 13–81:

```text
13: /**
14:  * What a stage sees of one running operation: fixed facts (operation, space, connection, actor) plus a
15:  * typed blackboard keyed by class name. Nothing here is bound in the container, so nested operations never
16:  * share a context. The target — space and connection — cannot change after the operation starts.
17:  */
18: #[Spi(kind: Spi::CALL, since: '1.0')]
19: interface OperationContext
20: {
21:     /** Operation id, e.g. "message.send". */
22:     public function operation(): string;
23: 
24:     /** Unique id of this run (correlation id in telemetry). */
25:     public function operationId(): string;
26: 
27:     public function space(): SpaceId;
28: 
29:     /** Database connection name of the space; every Db effect goes through it. */
30:     public function connection(): string;
31: 
32:     public function actor(): ActorRef;
33: 
34:     /** Kind of the acting actor; the operation's origin (human or agent). */
35:     public function origin(): ActorKind;
36: 
37:     /** Channel type the operation targets, when it targets a channel. */
38:     public function channelType(): ?string;
39: 
40:     public function phase(): Phase;
41: 
42:     /** The command as normalized so far. */
43:     public function command(): object;
44: 
45:     /**
46:      * Replace the command with a normalized copy of the same class. Allowed in Prepare and Validate only.
47:      *
48:      * @throws PipelineViolationException
49:      */
50:     public function replaceCommand(object $command): void;
51: 
52:     /**
53:      * Put a fact on the blackboard under its class name. A fact is written once; overwriting one is a
54:      * pipeline violation, so two stages can never race for the same key.
55:      *
56:      * @throws PipelineViolationException
57:      */
58:     public function put(object $fact): void;
59: 
60:     /**
61:      * @template T of object
62:      *
63:      * @param  class-string<T>  $fact
64:      * @return T
65:      *
66:      * @throws PipelineViolationException when the fact is absent
67:      */
68:     public function get(string $fact): object;
69: 
70:     /** @param  class-string  $fact */
71:     public function has(string $fact): bool;
72: 
73:     /**
74:      * Declare that the persisted transition changed nothing (idempotent no-op): Project, Record and
75:      * AfterCommit are skipped. Allowed in Persist only.
76:      *
77:      * @throws PipelineViolationException
78:      */
79:     public function markUnchanged(): void;
80: 
81:     public function changed(): bool;
```

## packages/core/src/Operations/Internal/Pipeline/OperationRunner.php

Source: [chatom/packages/core/src/Operations/Internal/Pipeline/OperationRunner.php](/home/vostrikov/projects/packages/chatom/packages/core/src/Operations/Internal/Pipeline/OperationRunner.php); SHA-256 `2f0daba48f0d36c3176de318266f5fcab7b7fa2646f3d0854430894efb729b99`.

Lines 17–97:

```text
17: /**
18:  * Runs one operation through its compiled pipeline:
19:  *
20:  *   Prepare → Authorize → Validate            (no transaction; a throw writes nothing)
21:  *   [transaction] Lock → [receipt] Reauthorize → Persist → Project → Record [/receipt] [/transaction]
22:  *   AfterCommit                                (after the outermost commit; failures are reported, not thrown)
23:  *
24:  * Project and Record are skipped when Persist marked the operation unchanged; AfterCommit is skipped for
25:  * unchanged and replayed operations, so a retry with the same client key has no second side effect.
26:  */
27: final readonly class OperationRunner
28: {
29:     private const array BEFORE_TRANSACTION = [Phase::Prepare, Phase::Authorize, Phase::Validate];
30: 
31:     public function __construct(
32:         private CompiledPipelines $pipelines,
33:         private StageResolver $resolver,
34:         private OperationTransactions $transactions,
35:         private ?ReceiptGate $receipts,
36:         private OperationObserver $observer,
37:     ) {}
38: 
39:     public function run(OperationStart $start): RunningOperation
40:     {
41:         $pipeline = $this->pipelines->get($start->operation);
42:         $operation = new RunningOperation($start);
43: 
44:         foreach (self::BEFORE_TRANSACTION as $phase) {
45:             $this->runPhase($pipeline, $phase, $operation);
46:         }
47: 
48:         $policy = $pipeline->receipts === null ? null : $this->resolver->make($pipeline->receipts);
49: 
50:         if ($policy instanceof PublicReceiptPolicy) {
51:             $policy = new PluginReceiptStrategy($policy);
52:         }
53: 
54:         $outcome = $this->transactions->transaction(
55:             $operation->connection(),
56:             function () use ($pipeline, $operation, $policy): ReceiptOutcome {
57:                 $this->runPhase($pipeline, Phase::Lock, $operation);
58:                 $work = function () use ($pipeline, $operation): void {
59:                     $this->runPhase($pipeline, Phase::Reauthorize, $operation);
60:                     $this->runPhase($pipeline, Phase::Persist, $operation);
61: 
62:                     if ($operation->changed()) {
63:                         $this->runPhase($pipeline, Phase::Project, $operation);
64:                         $this->runPhase($pipeline, Phase::Record, $operation);
65:                     }
66:                 };
67: 
68:                 if ($policy === null) {
69:                     $work();
70: 
71:                     return ReceiptOutcome::ran();
72:                 }
73: 
74:                 return ($this->receipts ?? throw new LogicException('No receipt gate is installed.'))->execute($policy, $operation, $work);
75:             },
76:         );
77: 
78:         $operation->finish();
79: 
80:         if ($outcome->deferred instanceof Throwable) {
81:             throw $outcome->deferred;
82:         }
83: 
84:         if ($outcome->ran && $operation->changed()) {
85:             $this->transactions->afterCommitOnConnection(
86:                 $operation->connection(),
87:                 fn () => $this->afterCommit($pipeline, $operation),
88:             );
89:         }
90: 
91:         try {
92:             $this->observer->completed($operation);
93:         } catch (Throwable) {
94:             // Optional telemetry must not change the result of a committed operation.
95:         }
96: 
97:         return $operation;
```

Lines 130–149:

```text
130:     private function executeStage(CompiledStage $stage, RunningOperation $operation): void
131:     {
132:         $operation->enter($stage->phase, $stage->id);
133:         $connection = $operation->connection();
134:         $level = $this->transactions->level($connection);
135: 
136:         try {
137:             $this->stage($stage)->handle($operation);
138:             $after = $this->transactions->level($connection);
139: 
140:             if ($after !== $level) {
141:                 throw $operation->violation("left the transaction level at {$after}, expected {$level}");
142:             }
143:         } catch (Throwable $exception) {
144:             if ($this->transactions->level($connection) > $level) {
145:                 $this->transactions->rollBackTo($connection, $level);
146:             }
147: 
148:             throw $exception;
149:         }
```

## packages/core/src/Spaces/Internal/Services/ConnectionSpaceTransactions.php

Source: [chatom/packages/core/src/Spaces/Internal/Services/ConnectionSpaceTransactions.php](/home/vostrikov/projects/packages/chatom/packages/core/src/Spaces/Internal/Services/ConnectionSpaceTransactions.php); SHA-256 `5687f3c147e9bdd74f375818f4e21a17e4744d8e88a5d65285b393b17d7b44f7`.

Lines 21–45:

```text
21: /**
22:  * The only space transaction boundary: every write runs on the space's named connection.
23:  * database.default is never read or changed.
24:  */
25: final readonly class ConnectionSpaceTransactions implements OperationTransactions, SpaceTransactions
26: {
27:     public function __construct(
28:         private SpaceDefinitions $spaces,
29:         private DatabaseManager $database,
30:     ) {}
31: 
32:     public function run(SpaceId $space, Closure $work): mixed
33:     {
34:         return $this->transaction($this->connectionName($space), $work);
35:     }
36: 
37:     public function afterCommit(SpaceId $space, Closure $callback): void
38:     {
39:         $this->afterCommitOnConnection($this->connectionName($space), $callback);
40:     }
41: 
42:     public function afterCommitOnConnection(string $connection, Closure $callback): void
43:     {
44:         $this->assertDeclared($connection);
45:         $this->database->connection($connection)->afterCommit($callback);
```

Lines 75–90:

```text
75:     public function transaction(string $connection, Closure $work): mixed
76:     {
77:         $this->assertDeclared($connection);
78:         $db = $this->database->connection($connection);
79:         $start = $db->transactionLevel();
80: 
81:         try {
82:             return $db->transaction($work);
83:         } catch (Throwable $exception) {
84:             if ($db->transactionLevel() > $start) {
85:                 $db->rollBack($start);
86:             }
87: 
88:             throw $exception;
89:         }
90:     }
```

## packages/core/src/Outbox/OutboxWriter.php

Source: [chatom/packages/core/src/Outbox/OutboxWriter.php](/home/vostrikov/projects/packages/chatom/packages/core/src/Outbox/OutboxWriter.php); SHA-256 `d69cb0d779997a6a5105861cf4b0bcf347e01139a45b3d78148a506f72163d16`.

Lines 21–44:

```text
21: /** Stages and consumes events atomically on the space connection.
22:  */
23: #[Spi(kind: Spi::CALL, since: '1.0')]
24: final readonly class OutboxWriter implements OutboundEventSink
25: {
26:     public function __construct(
27:         private SpaceTransactions $transactions, private SpaceDefinitions $spaces) {}
28: 
29:     /** @param array<string, mixed> $payload */
30:     public function dispatch(OutboxEvent $event, array $payload, ?string $space = null): void
31:     {
32:         $eventId = is_string($payload['event_id'] ?? null) && $payload['event_id'] !== ''
33:             ? $payload['event_id'] : (string) Str::uuid7();
34:         $topic = isset($payload['channel_id']) ? Values::string($payload['channel_id']) : null;
35:         $this->stage($this->spaces->get(SpaceId::from($space ?? 'default')), $event->value, $eventId, $payload, $topic);
36:     }
37: 
38:     /** @param array<string, mixed> $payload */
39:     public function stage(Space $space, string $event, string $eventId, array $payload, ?string $topic = null): OutboxStageResult
40:     {
41:         $connection = $this->transactions->connection($space->spaceId());
42:         $run = fn (): OutboxStageResult => $this->expand($space, $event, $eventId, $payload, $topic);
43: 
44:         return $connection->transactionLevel() === 0 ? $this->transactions->run($space->spaceId(), $run) : $run();
```

Lines 60–103:

```text
60:     private function expand(Space $space, string $event, string $eventId, array $payload, ?string $topic): OutboxStageResult
61:     {
62:         $connection = $this->transactions->connection($space->spaceId());
63:         $table = $space->table('outbox');
64:         // Laravel's insertOrIgnore uses ON CONFLICT DO NOTHING on PostgreSQL;
65:         // a duplicate does not poison the enclosing domain transaction.
66:         $connection->table($table)->insertOrIgnore([
67:             'id' => (string) Str::uuid7(),
68:             'event_id' => $eventId,
69:             'space' => $space->getId(),
70:             'topic' => $topic,
71:             'event' => $event,
72:             'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
73:             'available_at' => now(),
74:             'status' => 'open',
75:             'attempts' => 0,
76:             'created_at' => now(),
77:             'updated_at' => now(),
78:         ]);
79:         $row = $connection->table($table)->where('event_id', $eventId)->lockForUpdate()->first();
80: 
81:         if ($row === null) {
82:             throw new RuntimeException('Outbox intent was not recorded.');
83:         }
84: 
85:         if (! in_array(Values::string($row->status), ['open', 'pending'], true)) {
86:             return new OutboxStageResult($eventId, Values::string($row->status), true, []);
87:         }
88:         $stored = json_decode(Values::string($row->payload), true, flags: JSON_THROW_ON_ERROR);
89:         $record = new OutboxRecord($eventId, Values::string($row->event), is_array($stored) ? Values::record($stored) : [], $row->topic === null ? null : Values::string($row->topic));
90:         $worked = [];
91: 
92:         foreach (resolve(PluginContributions::class)->outboxConsumers() as $consumer) {
93:             if ($consumer->consume($space, $record)) {
94:                 $worked[] = $consumer->key();
95:             }
96:         }
97:         $status = $worked === [] ? 'settled' : 'fanned';
98:         $connection->table($table)->where('event_id', $eventId)->update([
99:             'status' => $status, 'dispatched_at' => now(), 'updated_at' => now(),
100:         ]);
101: 
102:         return new OutboxStageResult($eventId, $status, false, $worked);
103:     }
```

## packages/core/src/Errors/Exceptions/ChatomException.php

Source: [chatom/packages/core/src/Errors/Exceptions/ChatomException.php](/home/vostrikov/projects/packages/chatom/packages/core/src/Errors/Exceptions/ChatomException.php); SHA-256 `5a2f0a5c006d6e28fd96ab4765e7c5c9f8452dcdb6a66126ab6fa53aab764d93`.

Lines 14–45:

```text
14: /**
15:  * Root of every chatom failure. Machine identity is errorCode() (the wire `code`), never the class name:
16:  * core codes are ErrorCode values, a companion uses "<package_slug>.<code>" from its own enum.
17:  * context() carries identifiers and limits only — never secrets or payloads.
18:  */
19: #[Api(since: '1.0')]
20: abstract class ChatomException extends RuntimeException
21: {
22:     private const string CODE = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)?$/';
23: 
24:     private const string SECRET_KEY = '/(secret|token|password|signature|credential|authorization|api_?key)/i';
25: 
26:     /**
27:      * @param  array<string, scalar|null>  $context
28:      */
29:     public function __construct(
30:         private readonly string $errorCode,
31:         private readonly ErrorCategory $category,
32:         string $message,
33:         private readonly array $context = [],
34:         ?Throwable $previous = null,
35:     ) {
36:         if (preg_match(self::CODE, $errorCode) !== 1) {
37:             throw new InvalidArgumentException("Error code [{$errorCode}] must be snake_case, optionally \"<package>.<code>\".");
38:         }
39:         foreach ($context as $key => $value) {
40:             if (preg_match(self::SECRET_KEY, $key) === 1 || ! $this->isSafeDiagnosticValue($value)) {
41:                 throw new InvalidArgumentException("Error context key [{$key}] is not a safe scalar diagnostic.");
42:             }
43:         }
44:         parent::__construct($message, 0, $previous);
45:     }
```

## docs/architecture/layering-rules.md

Source: [chatom/docs/architecture/layering-rules.md](/home/vostrikov/projects/packages/chatom/docs/architecture/layering-rules.md); SHA-256 `5aa5970e9475943d77fd9dcf9b94e4abf905300973f7ddb343f170ea71e7389e`.

Lines 11–61:

```text
11: **Current graph (D20, D32).** "Core" in the rules below is the engine package `axiomasoft/chatom`. Companions require that engine and do not require each other, except `axiomasoft/chatom-forum` and `axiomasoft/chatom-voice-message`, which require `axiomasoft/chatom-attachments`. The engine does not require a companion. Search is a `suggest` entry, not part of `axiomasoft/chatom-full`.
12: 
13: ## Layers and what each is allowed to know
14: 
15: | Layer | Allowed to know | Not allowed to know |
16: | --- | --- | --- |
17: | **Core** | Laravel (`illuminate/*`) and its own public module types (outside `\Internal\`) | Nothing about feature-package namespaces; nothing about a specific host application |
18: | **Feature package** (forum, poll, voice-message, webhooks, broadcasting, attachments, presence, search) | The core's public surface — contracts, facade, declared extension points | Internal (unpublished) core classes; the internals of another feature package |
19: | **Consumer** | The public surface of *every installed package* — contracts, facades, config, resources | The internal implementation of any package, beyond what that package declares public |
20: | **Host application** | Is the source of `auth`/entities (a `Actor`, a `ActorProvider`) that packages consume through a contract | — the host is not a chatom package; the direction is reversed: packages read the host through a declared contract, never the other way around |
21: 
22: Knowledge flows strictly downward through this list. The host application sits outside the pyramid as a *supplier* of contract implementations, not as a layer of it.
23: 
24: The forum → attachments edge is the manifest `requires` of `chatom.forum` and is accepted by the [plugin dependency rule](./core-boundary#plugin-dependency-edges) (D04). All internal Composer edges use `self.version`. Core outbox owns intent and generic fanout; webhooks and broadcasting own delivery mechanisms. Contributions use the plugin SPI ([Plugins](../advanced/plugins)); companions never write directly to core configuration.
25: 
26: ## The invariants
27: 
28: These are the checkable rules the layering above reduces to. Treat them as the minimum required set — extending them is fine, weakening any of them is not.
29: 
30: | # | Invariant | How it's checked |
31: | --- | --- | --- |
32: | L1 | The core contains not a single `use` from a feature-package namespace | `tests/Arch/ArchitectureGatesTest.php` (scanner + core-companion mutation fixture) |
33: | L2 | The core's composer requirements do not include any of chatom's own feature packages | Reading the core's `composer.json` `require` section → expect `0` self-referential entries |
34: | L3 | A feature package reaches the core only through its declared public surface (contracts, facade, declared extension points) | `tests/Arch/ArchitectureGatesTest.php` checks the `\Internal\` segment and an internal-core mutation fixture |
35: | L4 | No package writes into another package's tables | `tests/Arch/ArchitectureGatesTest.php` foreign-table scanner + mutation fixture |
36: | L5 | Operation stage anchors and core order are declared; installed packages cannot remove or reorder core stages | Operation compiler tests with multiple companion contributions |
37: | L6 | The core boots and passes its own test suite with zero feature packages installed | A dedicated "core alone" Testbench bootstrap, run as its own CI job; its failure blocks release |
38: | L7 | A consumer is never required to know the internal structure of any installed package — only its public surface | Documentation coverage plus facade/public-API parity review |
39: 
40: ## Allowed mechanisms of extension
41: 
42: Every legitimate way a feature package reaches into the core is one of these; anything else is a violation of L3.
43: 
44: | Mechanism | How it's declared | Example |
45: | --- | --- | --- |
46: | **Manager / driver** (the five driver axes: broadcast, storage, push, search, presence) | `ChatManager`: `chatom.drivers.{type}` name resolves to a factory; declared via `PluginRegistrar::driver()`; fails fast on an unknown name | `Contracts\Drivers\{BroadcastDriver,PushDriver,SearchDriver,StorageDriver}`, `Contracts\Presence\PresenceStore` |
47: | **Space provider** | An abstract `ChatSpaceProvider` base class listed by fully-qualified class name in `chatom.spaces[]` (config-cache safe) | A forum space provider extending `ChatSpaceProvider` |
48: | **Node registry** (document nodes) | `PluginRegistrar::node(NodeDefinition)` in a plugin; the compiled plugin graph feeds the `NodeRegistry`; each node names a codec, an upgrade chain and, when interactive, a handler | The built-in link card; poll and voice-message packages contribute their own node types |
49: | **Config-swappable FQCN** | A contract plus a `chatom.<key>` config value naming the implementing class, with a default | `ActorProvider` (`chatom.actors.providers`), driver selection (`chatom.drivers`) |
50: | **Repository swap seam** | Repository contracts bound with `bind()` (not `singleton()`), so a non-Eloquent store can replace the default at any time | The core channel/message/reaction/pin/mention repository contracts; attachment repositories belong to attachments |
51: | **Container tags** | A class constant names the tag; multiple implementations of one role are collected under it | Available as a mechanism; not every axis in the package uses it today, but it is the sanctioned way to add a "list of providers" seam |
52: 
53: ## Forbidden forms of coupling
54: 
55: | Form | Why it's forbidden |
56: | --- | --- |
57: | **Reaching an unpublished core class** (any type under a module's `\Internal\` namespace) | Breaks L3 — a feature package becomes dependent on an internal detail the core is free to change without regard for semantic versioning |
58: | **Writing into another package's tables** | Breaks L4 — the table's owner loses control over its own invariants (who writes, and how) |
59: | **A cross-package foreign key added by another package's migration** (an `ALTER TABLE` against a table it doesn't own) | Breaks the "zero `ALTER`s on core tables" guarantee that lets a consumer upgrade a layer without a schema surprise |
60: | **Inheriting from a core class that isn't a declared extension point** — overriding a `protected` method instead of composing through a declared contract | Silently bypasses "reach the core only through its public surface" — inheriting from an internal class breaks on any core refactor without warning |
61: | **Reading another package's configuration directly, bypassing its contract or typed config wrapper** | Breaks configuration predictability — a package reads keys it doesn't own and has no way to learn when their shape changes |
```

## tests/Arch/ArchitectureGatesTest.php

Source: [chatom/tests/Arch/ArchitectureGatesTest.php](/home/vostrikov/projects/packages/chatom/tests/Arch/ArchitectureGatesTest.php); SHA-256 `3c188b2f71c9834161ed02a7b585f89662b3a076b50752a4b5ebfae443087d5c`.

Lines 22–49:

```text
22: test('L2: engine composer.json does not require a companion', function (): void {
23:     /** @var array{require?: array<string, string>} $json */
24:     $json = json_decode((string) file_get_contents(p09ArchitectureRepo().'/packages/core/composer.json'), true, flags: JSON_THROW_ON_ERROR);
25:     $require = array_keys($json['require'] ?? []);
26:     $companions = array_filter(
27:         $require,
28:         static fn (string $name): bool => str_starts_with($name, 'axiomasoft/chatom-')
29:             || str_starts_with($name, 'chatom/'),
30:     );
31: 
32:     expect($companions)->toBe([]);
33: });
34: 
35: test('companions require the engine and only forum and voice-message may require attachments', function (): void {
36:     $repo = p09ArchitectureRepo();
37:     $edges = [
38:         'forum' => ['axiomasoft/chatom', 'axiomasoft/chatom-attachments'],
39:         'poll' => ['axiomasoft/chatom'],
40:         'voice-message' => ['axiomasoft/chatom', 'axiomasoft/chatom-attachments'],
41:         'webhooks' => ['axiomasoft/chatom'],
42:         'broadcasting' => ['axiomasoft/chatom'],
43:         'attachments' => ['axiomasoft/chatom'],
44:         'presence' => ['axiomasoft/chatom'],
45:         'search' => ['axiomasoft/chatom'],
46:     ];
47: 
48:     foreach ($edges as $package => $allowed) {
49:         $path = $repo.'/packages/'.$package.'/composer.json';
```

Lines 70–109:

```text
70: test('L1 mutation: injected core→companion import is detected, then the live tree stays clean', function (): void {
71:     expect(ArchitectureGateScanner::companionImports(p09ArchitectureFixtures().'/core-companion'))->not->toBe([])
72:         ->and(ArchitectureGateScanner::companionImports(p09ArchitectureCoreSrc()))->toBe([]);
73: });
74: 
75: test('L3: companions do not import internal core prefixes', function (): void {
76:     $repo = p09ArchitectureRepo();
77:     $coreSrc = p09ArchitectureCoreSrc();
78:     $hits = [];
79: 
80:     foreach (['forum', 'poll', 'voice-message', 'webhooks', 'broadcasting', 'attachments', 'presence', 'search'] as $package) {
81:         $hits = array_merge($hits, ArchitectureGateScanner::internalCoreImports($repo.'/packages/'.$package.'/src', $coreSrc));
82:     }
83: 
84:     expect($hits)->toBe([]);
85: });
86: 
87: test('L3 mutation: injected internal-core import is detected, then companions stay clean', function (): void {
88:     $coreSrc = p09ArchitectureCoreSrc();
89: 
90:     expect(ArchitectureGateScanner::internalCoreImports(p09ArchitectureFixtures().'/internal-core', $coreSrc))->not->toBe([])
91:         ->and(ArchitectureGateScanner::internalCoreImports(p09ArchitectureRepo().'/packages/forum/src', $coreSrc))->toBe([]);
92: });
93: 
94: test('L4: core does not write companion-owned tables', function (): void {
95:     $foreign = array_merge(
96:         ArchitectureGateScanner::OWNED_TABLES['forum'],
97:         ArchitectureGateScanner::OWNED_TABLES['poll'],
98:         ArchitectureGateScanner::OWNED_TABLES['attachments'],
99:         ArchitectureGateScanner::OWNED_TABLES['webhooks'],
100:     );
101: 
102:     expect(ArchitectureGateScanner::foreignTableWrites(p09ArchitectureCoreSrc(), $foreign))->toBe([]);
103: });
104: 
105: test('L4 mutation: injected foreign-table write is detected, then core stays clean', function (): void {
106:     $coreSrc = p09ArchitectureCoreSrc();
107: 
108:     expect(ArchitectureGateScanner::foreignTableWrites(p09ArchitectureFixtures().'/foreign-table', ['chatom_forum_topics']))->not->toBe([])
109:         ->and(ArchitectureGateScanner::foreignTableWrites($coreSrc, ['chatom_forum_topics']))->toBe([]);
```
