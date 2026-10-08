# vaulter — source evidence

HEAD `f1af3d883030407bb954e98156ff7a8baaf59660`; dirty: `False`; working-tree snapshot.

## AGENTS.md

Source: [vaulter/AGENTS.md](/home/vostrikov/projects/packages/vaulter/AGENTS.md); SHA-256 `dc197e076b7ea44eb8ecfb298e9a94ca9ef69557f466ba4d4127615ddbb55e39`.

Lines 1–18:

```text
1: # Vaulter — инструкции для агентов
2: 
3: Vaulter — PHP-монорепозиторий пакетов Laravel. Работай только в затронутом пакете
4: `packages/<name>/`; не меняй соседние пакеты и не перезаписывай чужие изменения.
5: 
6: Используй только нативно доступные навыки SKM: компактный marketplace-профиль задан в
7: `.swissknife.json` (как CoreX). Локальный `.agents/skills/` — только CORE (см.
8: `scripts/sync-agent-skills.sh`); не держи полный php-package vendor. Не загружай
9: справочники, планы или навыки «на всякий случай» — выбирай их по задаче.
10: 
11: MCP-серверы `maind`, `codegraph` и `perplexity` настроены для scope
12: `project:vaulter`. Проверяй состояние `maind health --cwd` при изменении среды или
13: симптомах, а не перед каждой обычной правкой.
14: 
15: Перед изменением кода прочитай ближайшие проектные инструкции и существующие тесты.
16: Проверяй затронутый пакет; не объявляй пропущенную или упавшую проверку успешной.
17: Пуш, публикация, удаление данных и перезапись несвязанных изменений требуют явного
18: запроса владельца.
```

## packages/core/composer.json

Source: [vaulter/packages/core/composer.json](/home/vostrikov/projects/packages/vaulter/packages/core/composer.json); SHA-256 `232f579fffff6eedeb3152ce4b1e0a8b8139bfd096c568c0e0868fb638d27dfd`.

Lines 1–95:

```text
1: {
2:     "name": "axioma-studio/vaulter-core",
3:     "description": "Embeddable document management for Laravel — file manager, TipTap editor, version history and real-time collaboration, integrated through adapters.",
4:     "type": "library",
5:     "license": "MIT",
6:     "keywords": [
7:         "laravel",
8:         "documents",
9:         "tiptap",
10:         "collaboration",
11:         "yjs",
12:         "versioning",
13:         "editor",
14:         "file-manager"
15:     ],
16:     "homepage": "https://github.com/axiomasoft/vaulter",
17:     "authors": [
18:         {
19:             "name": "Dmitry Vostrikov",
20:             "email": "dv.vostrikov@gmail.com"
21:         }
22:     ],
23:     "support": {
24:         "issues": "https://github.com/axiomasoft/vaulter/issues",
25:         "source": "https://github.com/axiomasoft/vaulter"
26:     },
27:     "require": {
28:         "php": "^8.4",
29:         "illuminate/contracts": "^12.0|^13.0",
30:         "illuminate/database": "^12.0|^13.0",
31:         "illuminate/support": "^12.0|^13.0",
32:         "spatie/laravel-package-tools": "^1.92",
33:         "staudenmeir/laravel-adjacency-list": "^1.0",
34:         "psr/http-message": "^1.1|^2.0"
35:     },
36:     "require-dev": {
37:         "axioma-studio/vaulter-drive": "self.version",
38:         "axioma-studio/vaulter-media": "self.version",
39:         "orchestra/testbench": "^10.0|^11.0",
40:         "pestphp/pest": "^4.0",
41:         "pestphp/pest-plugin-laravel": "^4.0",
42:         "spatie/laravel-permission": "^8.0",
43:         "nikic/php-parser": "^5.7"
44:     },
45:     "suggest": {
46:         "spatie/laravel-permission": "Required only when using the 'spatie' host-role driver (^8.0).",
47:         "axioma-studio/vaulter-filament": "Filament admin UI for documents, folders and permissions.",
48:         "axioma-studio/vaulter-media": "Content-addressed file/blob layer (CAS): the concrete ContentStore/StorageDriver/BlobStore engine behind the Contracts\\Cas seam. Required for Drive file upload/download/versioning.",
49:         "axioma-studio/vaulter-drive": "File/folder tree behaviour + resumable uploads: StorageManager, the DriveHandle factory, PurgeTrash/SweepUploads. Required for Vaulter::for()/forStorage() and the Drive REST surface.",
50:         "axioma-studio/vaulter-documents": "Document-node behaviour: the format-handler registry, TipTap/export engines and real-time collaboration (Hocuspocus JWT minting needs firebase/php-jwt ^6.0). Required for Vaulter::registerHandler()/registerExporter() and the document REST surface."
51:     },
52:     "autoload": {
53:         "psr-4": {
54:             "Vaulter\\": "src/"
55:         }
56:     },
57:     "autoload-dev": {
58:         "psr-4": {
59:             "Vaulter\\Tests\\": "tests/",
60:             "Vaulter\\Database\\Factories\\": "database/factories/"
61:         }
62:     },
63:     "extra": {
64:         "laravel": {
65:             "providers": [
66:                 "Vaulter\\VaulterServiceProvider"
67:             ],
68:             "aliases": {
69:                 "Vaulter": "Vaulter\\Facades\\Vaulter"
70:             }
71:         },
72:         "branch-alias": {
73:             "dev-main": "0.1.x-dev"
74:         }
75:     },
76:     "repositories": [
77:         {
78:             "type": "path",
79:             "url": "../drive",
80:             "options": {
81:                 "symlink": true
82:             }
83:         },
84:         {
85:             "type": "path",
86:             "url": "../media",
87:             "options": {
88:                 "symlink": true
89:             }
90:         }
91:     ],
92:     "minimum-stability": "dev",
93:     "prefer-stable": true,
94:     "config": {
95:         "allow-plugins": {
```

## packages/core/config/vaulter.php

Source: [vaulter/packages/core/config/vaulter.php](/home/vostrikov/projects/packages/vaulter/packages/core/config/vaulter.php); SHA-256 `61321bcbf5fd25ae8d876981dbf87658d605af1278943b8392c3ab08a71912ff`.

Lines 33–69:

```text
33: /** Publish with the vaulter-config tag. */
34: return [
35:     'database' => [
36:         'connection' => env('VAULTER_DB_CONNECTION'),
37:         'table_prefix' => env('VAULTER_TABLE_PREFIX', 'v_'),
38:     ],
39:     'ids' => [
40:         'strategy' => env('VAULTER_ID_STRATEGY', 'ulid'),
41:         'host_keys' => env('VAULTER_HOST_KEYS', 'string'),
42:         'strategies' => [
43:             'bigint' => AutoIncrementIds::class,
44:             'ulid' => UlidIds::class,
45:             'uuid7' => Uuid7Ids::class,
46:         ],
47:     ],
48:     'tenancy' => ['enabled' => env('VAULTER_TENANT_ENABLED', false), 'resolver' => null],
49:     'profiles' => ['default' => DefaultProfile::class, 'providers' => []],
50:     'scan' => [
51:         'mode' => null, 'required' => null, 'scanner' => null, 'scanner_key' => null,
52:         'scanner_version' => null, 'policy_version' => null, 'rollout_key' => null,
53:     ],
54:     'features' => [
55:         'grants' => true,
56:         'activity' => true,
57:         'webhooks' => true,
58:         'share_links' => false,
59:         'public_listing' => false,
60:         'spreadsheets' => false, // Transitional core key until P4.3.
61:     ],
62:     'access' => [
63:         'permission_driver' => env('VAULTER_PERMISSION_DRIVER', 'database'),
64:         'permission_drivers' => [
65:             'database' => DatabasePermissionDriver::class,
66:             'gate' => GatePermissionDriver::class,
67:             'spatie' => SpatiePermissionDriver::class,
68:             'null' => NullPermissionDriver::class,
69:         ],
```

## packages/core/src/Configuration/DatabaseConfig.php

Source: [vaulter/packages/core/src/Configuration/DatabaseConfig.php](/home/vostrikov/projects/packages/vaulter/packages/core/src/Configuration/DatabaseConfig.php); SHA-256 `e02ec4d75f7c667e95dfb7eb2ae65de26c30e578cfb0a32ba4fbd14739bac43e`.

Lines 9–43:

```text
9: /**
10:  * Typed accessors over config('vaulter.*'). All package code reads configuration
11:  * through here so the surface is type-safe and swappable models resolve consistently.
12:  */
13: final readonly class DatabaseConfig
14: {
15:     public function __construct(private Repository $config) {}
16: 
17:     public function tablePrefix(): string
18:     {
19:         $value = $this->config->get('vaulter.database.table_prefix', 'v_');
20: 
21:         return is_string($value) ? $value : 'v_';
22:     }
23: 
24:     public function table(string $name): string
25:     {
26:         return $this->tablePrefix().$name;
27:     }
28: 
29:     public function legacyTable(string $legacyEntity): string
30:     {
31:         $mapped = $this->config->get('vaulter.table_names.'.$legacyEntity);
32:         $base = is_string($mapped) && $mapped !== '' ? $mapped : $legacyEntity;
33: 
34:         return $this->tablePrefix().$base;
35:     }
36: 
37:     public function connection(): ?string
38:     {
39:         $value = $this->config->get('vaulter.database.connection');
40: 
41:         return is_string($value) && $value !== '' ? $value : null;
42:     }
43: }
```

## packages/core/src/Schema/IdStrategyResolver.php

Source: [vaulter/packages/core/src/Schema/IdStrategyResolver.php](/home/vostrikov/projects/packages/vaulter/packages/core/src/Schema/IdStrategyResolver.php); SHA-256 `b299c5821b07699fe4e43f719ee97f9723447f78b4ca5f3f3e5ca31f3d7855b6`.

Lines 11–47:

```text
11: /**
12:  * Resolves a primary-key strategy by its config key (ulid|uuid7|bigint).
13:  * Falls back to configuration key vaulter.ids.strategy when no key is supplied; the
14:  * key->class map lives in configuration key vaulter.ids.strategies, and instances come
15:  * from the container so strategies may declare their own dependencies later.
16:  *
17:  * The resolver is registered as a container singleton, so the resolved instance
18:  * map is cached for the lifetime of the request/worker.
19:  */
20: final class IdStrategyResolver
21: {
22:     public function __construct(private readonly IdsConfig $idsConfig) {}
23: 
24:     /**
25:      * @var array<string, IdStrategy>
26:      */
27:     private array $resolved = [];
28: 
29:     public function resolve(?string $key = null): IdStrategy
30:     {
31:         $key ??= $this->idsConfig->strategy();
32: 
33:         if (isset($this->resolved[$key])) {
34:             return $this->resolved[$key];
35:         }
36: 
37:         $strategies = $this->idsConfig->strategies();
38: 
39:         if (!isset($strategies[$key])) {
40:             throw UnknownIdStrategyException::forKey($key, array_keys($strategies));
41:         }
42: 
43:         /** @var IdStrategy $strategy */
44:         $strategy = app()->make($strategies[$key]);
45: 
46:         return $this->resolved[$key] = $strategy;
47:     }
```

## packages/core/src/Schema/HostKeyColumns.php

Source: [vaulter/packages/core/src/Schema/HostKeyColumns.php](/home/vostrikov/projects/packages/vaulter/packages/core/src/Schema/HostKeyColumns.php); SHA-256 `392c79977e3b0ef4b9488c5207ef18e9367d27f895979432b4c3c60662a7e82b`.

Lines 11–40:

```text
11: final class HostKeyColumns
12: {
13:     public static function add(Blueprint $table, string $column, bool $nullable, bool $index): void
14:     {
15:         $definition = match (app(IdsConfig::class)->hostKeys()) {
16:             'string' => $table->string($column, 64),
17:             'bigint' => $table->unsignedBigInteger($column),
18:             'uuid' => $table->uuid($column),
19:             'ulid' => $table->char($column, 26),
20:         };
21: 
22:         $definition->nullable($nullable);
23: 
24:         if ($index) {
25:             $definition->index();
26:         }
27:     }
28: 
29:     public static function sqlType(string $driver): string
30:     {
31:         if (!in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
32:             throw new InvalidArgumentException('[Vaulter] Unsupported host-key SQL driver ['.$driver.'].');
33:         }
34: 
35:         return match (app(IdsConfig::class)->hostKeys()) {
36:             'string' => 'varchar(64)',
37:             'bigint' => $driver === 'pgsql' ? 'bigint' : 'bigint unsigned',
38:             'uuid' => $driver === 'pgsql' ? 'uuid' : 'char(36)',
39:             'ulid' => 'char(26)',
40:         };
```

## packages/core/src/Schema/VaulterSchema.php

Source: [vaulter/packages/core/src/Schema/VaulterSchema.php](/home/vostrikov/projects/packages/vaulter/packages/core/src/Schema/VaulterSchema.php); SHA-256 `6150179958eed5c7065447879e9ddf0030afa60dbc4e2224d273d84864f6e8be`.

Lines 11–16:

```text
11: /**
12:  * Static migration helper over the resolved IdStrategy. Hand-written migrations
13:  * call these helpers instead of hard-coding column types, so "ulid vs uuid7 vs
14:  * bigint" stays driven by configuration key vaulter.ids.strategy. Key/reference/morphs route through the strategy;
15:  * hostKey/actor/tenant use the independent host-key type; panel/bits/reference add
16:  * the per-table convention columns from the master plan §2.0.
```

Lines 62–94:

```text
62:      * Reference to a host record using ids.host_keys, without a foreign key.
63:      */
64:     public static function hostKey(Blueprint $table, string $column, bool $nullable = false): void
65:     {
66:         HostKeyColumns::add(table: $table, column: $column, nullable: $nullable, index: false);
67:     }
68: 
69:     /** Reference to a nullable host actor, without an index. */
70:     public static function actor(Blueprint $table, string $column, bool $nullable = true): void
71:     {
72:         self::hostKey(table: $table, column: $column, nullable: $nullable);
73:     }
74: 
75:     /**
76:      * Optional tenant column — emitted only when tenancy is enabled. Host-scoped:
77:      * Vaulter never scopes by it (D8).
78:      */
79:     public static function tenant(Blueprint $table): void
80:     {
81:         if (!app(TenancyConfig::class)->enabled()) {
82:             return;
83:         }
84: 
85:         HostKeyColumns::add(table: $table, column: TenancyConfig::COLUMN, nullable: true, index: true);
86:     }
87: 
88:     /**
89:      * Add the panel discriminator column (default-panel root tables).
90:      */
91:     public static function panel(Blueprint $table): void
92:     {
93:         $table->string(column: 'profile')->default(value: 'default')->index();
94:     }
```

## packages/core/src/Tenancy/TenancyBoundary.php

Source: [vaulter/packages/core/src/Tenancy/TenancyBoundary.php](/home/vostrikov/projects/packages/vaulter/packages/core/src/Tenancy/TenancyBoundary.php); SHA-256 `d01c860c35da161a5ca22e5913b1307e080f21a7321e718af1d0215ffe17ecd5`.

Lines 19–44:

```text
19: /** @internal Central boundary; resolver values and supplied models are never cached. */
20: final readonly class TenancyBoundary
21: {
22:     public function __construct(private TenancyConfig $config, private VaulterConfig $models,
23:         private Container $container, private TenancyBypass $bypass) {}
24: 
25:     public static function normalize(int|string|null $tenant): ?string
26:     {
27:         return $tenant === null ? null : (string) $tenant;
28:     }
29: 
30:     public function enforced(): bool
31:     {
32:         return $this->config->enabled() && !$this->bypass->active();
33:     }
34: 
35:     public function current(): string
36:     {
37:         $shareDrive = $this->container->make(ShareTenantContext::class)->drive();
38:         $current = self::normalize($shareDrive === null ? $this->container->make(TenantResolver::class)->current() : $shareDrive->tenant_id);
39:         if ($current === null) {
40:             throw new TenancyMisconfiguredException('Strict tenancy requires a current tenant.');
41:         }
42: 
43:         return $current;
44:     }
```

Lines 124–174:

```text
124:     /** @template T of Model
125:      * @param  Builder<T> $query
126:      * @return Builder<T>
127:      */
128:     public function constrain(Builder $query, ?string $driveRelation = null): Builder
129:     {
130:         if (!$this->enforced()) {
131:             return $query;
132:         }
133:         $tenant = $this->current();
134:         $shareDrive = $this->container->make(ShareTenantContext::class)->drive();
135:         if ($shareDrive !== null) {
136:             if ($query->getModel() instanceof Drive) {
137:                 $query->whereKey($shareDrive->getRawOriginal($shareDrive->getKeyName()));
138:             } elseif ($driveRelation !== null) {
139:                 $query->whereHas($driveRelation, fn (Builder $drive): Builder => $drive->whereKey($shareDrive->getRawOriginal($shareDrive->getKeyName())));
140:             } else {
141:                 $query->where($query->getModel()->qualifyColumn('drive_id'), $shareDrive->getRawOriginal($shareDrive->getKeyName()));
142:             }
143:         }
144:         if ($query->getModel() instanceof Drive) {
145:             return $this->tenantPredicate($query, $tenant);
146:         }
147:         $this->tenantPredicate($query, $tenant);
148:         if ($driveRelation !== null) {
149:             return $query->whereHas($driveRelation, fn (Builder $drive): Builder => $this->tenantPredicate($drive, $tenant));
150:         }
151:         $driveClass = $this->models->model('drive');
152: 
153:         return $query->whereIn($query->getModel()->qualifyColumn('drive_id'),
154:             $this->tenantPredicate($driveClass::query()->select('id'), $tenant));
155:     }
156: 
157:     /** @template T of Model
158:      * @param  Builder<T> $query
159:      * @return Builder<T>
160:      */
161:     private function tenantPredicate(Builder $query, string $tenant): Builder
162:     {
163:         $type = $this->models->ids()->hostKeys();
164:         if (($type === 'bigint' && filter_var($tenant, FILTER_VALIDATE_INT) === false)
165:             || ($type === 'uuid' && preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $tenant) !== 1)) {
166:             return $query->whereRaw('1 = 0');
167:         }
168:         $column = $query->getModel()->qualifyColumn('tenant_id');
169:         // Keep the indexed predicate, then reject numeric coercion, collations,
170:         // CHAR padding and case folding using exact normalized string identity.
171:         $query->where($column, $tenant);
172: 
173:         return $query->whereRaw(new TenantIdentityPredicate($column, $query->getModel()->getConnection()->getDriverName()), [$tenant]);
174:     }
```

## packages/core/src/Contracts/Tenancy/TenantResolver.php

Source: [vaulter/packages/core/src/Contracts/Tenancy/TenantResolver.php](/home/vostrikov/projects/packages/vaulter/packages/core/src/Contracts/Tenancy/TenantResolver.php); SHA-256 `df3b71d845218ed72004abeb8bea43527c6a4fd9d833c07e09a6018257869c57`.

Lines 5–10:

```text
5: namespace Vaulter\Contracts\Tenancy;
6: 
7: interface TenantResolver
8: {
9:     public function current(): int|string|null;
10: }
```

## packages/core/src/Jobs/Middleware/RestoreTenantContext.php

Source: [vaulter/packages/core/src/Jobs/Middleware/RestoreTenantContext.php](/home/vostrikov/projects/packages/vaulter/packages/core/src/Jobs/Middleware/RestoreTenantContext.php); SHA-256 `f85971b1bca9c8578b7c31e1f76ba952d5b6740e7d3c078b38504873954e1377`.

Lines 18–69:

```text
18: /** Host restoration middleware precedes this validator; TenantResolver is read-only. */
19: final class RestoreTenantContext
20: {
21:     private ?Actor $actor = null;
22: 
23:     public function __construct(private readonly int|string $driveId, private readonly int|string|null $tenantId,
24:         private readonly ?ActorRef $actorRef = null) {}
25: 
26:     public function actor(): ?Actor
27:     {
28:         return $this->actor;
29:     }
30: 
31:     public function handle(object $job, Closure $next): mixed
32:     {
33:         return app(TenancyBypass::class)->suspend(function () use ($job, $next): mixed {
34:             try {
35:                 app(TenancyBoundary::class)->assertJobDrive($this->driveId, $this->tenantId);
36:                 if ($this->actorRef !== null) {
37:                     $ref = $this->actorRef;
38:                     if ($ref->type === ActorRef::SYSTEM_TYPE) {
39:                         $this->actor = Actor::system($ref->reason ?? '');
40:                     } else {
41:                         $configuration = app(Repository::class);
42:                         $guard = $configuration->get('auth.defaults.guard');
43:                         $providerName = is_string($guard) ? $configuration->get('auth.guards.'.$guard.'.provider') : null;
44:                         $user = is_string($providerName) ? Auth::createUserProvider($providerName)?->retrieveById($ref->id) : null;
45:                         if (!$user instanceof Authorizable) {
46:                             throw NodeNotFoundException::forId('actor');
47:                         }
48:                         $identifier = $user->getAuthIdentifier();
49:                         if ((!is_int($identifier) && !is_string($identifier)) || Actor::user($user)->ref()->type !== $ref->type
50:                             || (string) $identifier !== (string) $ref->id) {
51:                             throw NodeNotFoundException::forId('actor');
52:                         }
53:                         $this->actor = Actor::user($user);
54:                     }
55:                 }
56:             } catch (NodeNotFoundException|TenancyMisconfiguredException $exception) {
57:                 if (method_exists($job, 'fail')) {
58:                     $job->fail($exception);
59: 
60:                     return null;
61:                 }
62:                 throw $exception;
63:             }
64:             try {
65:                 return $next($job);
66:             } finally {
67:                 $this->actor = null;
68:             }
69:         });
```

## packages/core/src/Profiles/ProfileRegistry.php

Source: [vaulter/packages/core/src/Profiles/ProfileRegistry.php](/home/vostrikov/projects/packages/vaulter/packages/core/src/Profiles/ProfileRegistry.php); SHA-256 `b2858fbac2cbdf2f74ac77f29e58ae78b2f0a1a22e90fe9c59fc43c123861584`.

Lines 13–69:

```text
13: final class ProfileRegistry
14: {
15:     /** @var array<string, Profile> */
16:     private array $profiles = [];
17: 
18:     private bool $frozen = false;
19: 
20:     public function __construct(private ?ContainerContract $container = null) {}
21: 
22:     public function register(ProfileProvider|Profile|string $profile): void
23:     {
24:         if ($this->frozen) {
25:             throw new LogicException('The profile registry is frozen.');
26:         }
27:         if (is_string($profile)) {
28:             if (!is_a($profile, ProfileProvider::class, true)) {
29:                 throw new InvalidProfileException('A profile provider FQCN is required.');
30:             }
31:             $profile = ($this->container ?? Container::getInstance())->make($profile);
32:             if (!$profile instanceof ProfileProvider) {
33:                 throw new InvalidProfileException('The container must return a ProfileProvider.');
34:             }
35:         }
36:         if ($profile instanceof ProfileProvider) {
37:             $profile = $profile->resolve();
38:         }
39:         $key = $profile->getKey();
40:         if ($key === '' || isset($this->profiles[$key])) {
41:             throw new InvalidProfileException('Profile keys must be nonempty and unique; duplicate "'.$key.'".');
42:         }
43:         $snapshot = clone $profile;
44:         $snapshot->freeze();
45:         $this->profiles[$key] = $snapshot;
46:     }
47: 
48:     /** @param list<class-string<ProfileProvider>> $providerClasses
49:      * @param class-string<ProfileProvider> $defaultClass
50:      */
51:     public static function fromConfig(array $providerClasses, string $defaultClass, ?ContainerContract $container = null): self
52:     {
53:         $registry = new self($container);
54:         foreach ([$defaultClass, ...$providerClasses] as $class) {
55:             $registry->register($class);
56:         }
57:         $registry->freeze();
58: 
59:         return $registry;
60:     }
61: 
62:     public function freeze(): void
63:     {
64:         $this->frozen = true;
65:     }
66: 
67:     public function get(string $key): Profile
68:     {
69:         return $this->profiles[$key] ?? throw UnknownProfileException::forName($key, array_keys($this->profiles));
```

## packages/core/src/Profiles/ProfileProvider.php

Source: [vaulter/packages/core/src/Profiles/ProfileProvider.php](/home/vostrikov/projects/packages/vaulter/packages/core/src/Profiles/ProfileProvider.php); SHA-256 `51c4efb9cc3e5ce627bd616c634a22eef637486bb02abb8200035a77d36890db`.

Lines 7–16:

```text
7: /** A config-cache-safe provider of a named drive policy. */
8: abstract class ProfileProvider
9: {
10:     abstract public function profile(Profile $profile): Profile;
11: 
12:     public function resolve(): Profile
13:     {
14:         return $this->profile(new Profile);
15:     }
16: }
```

## packages/core/src/Handlers/FileHandlerRegistry.php

Source: [vaulter/packages/core/src/Handlers/FileHandlerRegistry.php](/home/vostrikov/projects/packages/vaulter/packages/core/src/Handlers/FileHandlerRegistry.php); SHA-256 `c436d0b2bee6725eb07745705910a6a14cda5b8913e7c330e7a1700461d7ea95`.

Lines 15–38:

```text
15: /**
16:  * THE format-extensibility engine (master plan §4.5 / §7 / H.5). Resolves the best
17:  * {@see FileHandler} for a {@see FileDescriptor} and a requested {@see
18:  * Capability}, from three cache-safe sources merged once:
19:  *
20:  *   1. the tagged container binding 'vaulter.file_handlers' (FQCN-tagged, no closures);
21:  *   2. configuration key vaulter.handlers — an FQCN list (config-cache safe);
22:  *   3. runtime registrations via Vaulter::handlers()->register()/register().
23:  *
24:  * Resolution order (§4.5): mime-first → extension-fallback → capability-filter →
25:  * priority-sort → the DownloadOnlyHandler null-object (so a resolution NEVER returns
26:  * null). The registry is the SOURCE OF TRUTH for formats — a runtime-registered
27:  * format works with NO migration and NO core/enum change (H.5: the sealed NodeKind/
28:  * DocumentType enums are only built-in sugar, never a gate).
29:  *
30:  * SECURITY (H.5): routing is keyed on FileDescriptor::canonicalMime() — the
31:  * server-sniffed detectedMime, never the client claimedMime. A spoofed Content-Type
32:  * cannot force an editable handler onto hostile bytes.
33:  *
34:  * Resolutions are MEMOISED by (canonicalMime, extension, capability) so the hot path
35:  * (a folder listing of like files) sorts the handler set once. The cache is keyed on
36:  * the routing inputs only — distinct descriptors that route identically share a key
37:  * deterministically, never collide across capabilities.
38:  */
```

Lines 70–101:

```text
70:     /**
71:      * Register a handler at runtime (the Vaulter::handlers()->register seam). FQCN or
72:      * instance; an FQCN is resolved through the container. Re-priming the merged set
73:      * + clearing the resolution cache keeps a later resolve correct.
74:      *
75:      * @param FileHandler|class-string<FileHandler> $handler
76:      */
77:     public function register(FileHandler|string $handler): void
78:     {
79:         $this->runtime[] = $this->checkedInstance($handler);
80:         $this->handlers = null;
81:         $this->cache = [];
82:     }
83: 
84:     /**
85:      * Resolve the best handler for the file and the requested capability. NEVER
86:      * returns null — the DownloadOnlyHandler null-object is the guaranteed fallback.
87:      *
88:      * Algorithm (§4.5): collect mime-matching handlers (canonical/sniffed mime,
89:      * exact then wildcard); if none, fall back to extension-matching handlers; keep
90:      * only those whose capabilities satisfy $capability; sort by priority desc; take
91:      * the first, else DownloadOnly.
92:      */
93:     public function resolve(FileDescriptor $file, Capability $capability = Capability::Download): FileHandler
94:     {
95:         $cacheKey = $this->cacheKey($file, $capability);
96: 
97:         if (isset($this->cache[$cacheKey])) {
98:             return $this->cache[$cacheKey];
99:         }
100: 
101:         return $this->cache[$cacheKey] = $this->resolveUncached($file, $capability);
```

## packages/documents/src/Registries/DocumentTypeRegistry.php

Source: [vaulter/packages/documents/src/Registries/DocumentTypeRegistry.php](/home/vostrikov/projects/packages/vaulter/packages/documents/src/Registries/DocumentTypeRegistry.php); SHA-256 `5b1da6cc60ef14de9069bf87dc8c3c70eedd2451bcc8dd30f66cd8a975ee3438`.

Lines 7–23:

```text
7: final class DocumentTypeRegistry
8: {
9:     /** @var array<string, array<string, mixed>> */
10:     private array $types = [];
11: 
12:     /** @param array<string, mixed> $definition */
13:     public function register(string $type, array $definition): void
14:     {
15:         $this->types[$type] = $definition;
16:     }
17: 
18:     /** @return array<string, array<string, mixed>> */
19:     public function all(): array
20:     {
21:         return $this->types;
22:     }
23: }
```

## packages/core/src/Operations/OperationContext.php

Source: [vaulter/packages/core/src/Operations/OperationContext.php](/home/vostrikov/projects/packages/vaulter/packages/core/src/Operations/OperationContext.php); SHA-256 `b430c7010d9772d29be36b92c0964ff3cc9d6951d2591c1f4db647a62b61d148`.

Lines 12–41:

```text
12: final readonly class OperationContext
13: {
14:     private int|string $driveKey;
15: 
16:     public function __construct(
17:         public Drive $drive,
18:         public Actor $actor,
19:         public string $correlationId,
20:         public ?string $idempotencyKey = null,
21:     ) {
22:         if (trim($correlationId) === '' || strlen($correlationId) > 191 || preg_match('/[\x00-\x1F\x7F]/', $correlationId) === 1) {
23:             throw new InvalidArgumentException('[Vaulter] Correlation ID must be nonempty, at most 191 bytes and contain no control characters.');
24:         }
25:         $key = $drive->getRawOriginal($drive->getKeyName());
26:         if (!$drive->exists || (!is_int($key) && !is_string($key)) || $key === '') {
27:             throw new InvalidArgumentException('[Vaulter] An operation context requires a persisted drive.');
28:         }
29:         $this->driveKey = $key;
30:     }
31: 
32:     public static function system(Drive $drive, string $reason, ?string $correlationId = null): self
33:     {
34:         return new self(drive: $drive, actor: Actor::system($reason), correlationId: $correlationId ?? (string) Str::ulid());
35:     }
36: 
37:     /** @internal The bound identity must survive edits to the caller's model. */
38:     public function driveId(): int|string
39:     {
40:         return $this->driveKey;
41:     }
```

## packages/core/src/VaulterServiceProvider.php

Source: [vaulter/packages/core/src/VaulterServiceProvider.php](/home/vostrikov/projects/packages/vaulter/packages/core/src/VaulterServiceProvider.php); SHA-256 `63d4327f7b59437ab46ae123a6cc19ea50ff39808f3ebab5c4e28e3bf8123cf0`.

Lines 19–59:

```text
19: final class VaulterServiceProvider extends PackageServiceProvider
20: {
21:     public function configurePackage(Package $package): void
22:     {
23:         $package
24:             ->name('vaulter')
25:             ->hasConfigFile()
26:             ->hasTranslations()
27:             ->discoversMigrations()
28:             ->runsMigrations()
29:             ->hasRoute('api')
30:             ->hasInstallCommand(static function (InstallCommand $command): void {
31:                 $command
32:                     ->publishConfigFile()
33:                     ->publishMigrations()
34:                     ->askToRunMigrations()
35:                     ->askToStarRepoOnGitHub('axiomasoft/vaulter');
36:             });
37:     }
38: 
39:     public function packageRegistered(): void
40:     {
41:         (new BindingsRegistrar)->register($this->app);
42:         $normalizer = $this->app->make(ConfigNormalizer::class);
43:         $normalizer->registerDefaults('vaulter', __DIR__.'/../config/vaulter.php');
44:         $this->app->booting(function (): void {
45:             $normalizer = $this->app->make(ConfigNormalizer::class);
46:             $normalizer->normalize($this->app->make('config'), $normalizer->packageDefaults());
47:         });
48:         (new AccessRegistrar)->register($this->app);
49:         (new ConsoleRegistrar)->register($this->app);
50:     }
51: 
52:     public function packageBooted(): void
53:     {
54:         $this->app->make(ConfigValidator::class)->validate($this->app->make(VaulterConfig::class), $this->app);
55:         (new HttpRegistrar)->boot($this->app);
56:         (new AccessRegistrar)->boot($this->app);
57:         (new EventsRegistrar)->boot($this->app);
58:         (new BindingsRegistrar)->boot($this->app);
59:     }
```

## packages/core/src/Operations/AccessOperations.php

Source: [vaulter/packages/core/src/Operations/AccessOperations.php](/home/vostrikov/projects/packages/vaulter/packages/core/src/Operations/AccessOperations.php); SHA-256 `e756c639d7e418879619f7ba336ef8a5947b398f43d8d1cd0d0b3d6d64cb37c3`.

Lines 24–60:

```text
24: use Vaulter\Exceptions\Nodes\NodeNotFoundException;
25: use Vaulter\Models\Access\NodeGrant;
26: use Vaulter\Models\Drive\Node;
27: 
28: final readonly class AccessOperations implements Contract
29: {
30:     public function __construct(private OperationContext $context, private NodeLocator $locator, private OperationGuard $guard, private AccessResolver $resolver, private VaulterConfig $config, private GrantNodeAccessAction $create, private RevokeNodeAccessAction $delete, private SetNodeInheritanceAction $inheritance) {}
31: 
32:     /** @return Collection<int, NodeGrant> */
33:     public function grants(Node|int|string $node): Collection
34:     {
35:         $node = $this->located($node);
36:         $this->guard->authorizeGrants(context: $this->context, node: $node);
37:         $class = $this->config->model('grant');
38: 
39:         return $class::query()->where('node_id', $node->id)->orderByDesc('created_at')->orderByDesc('id')->get();
40:     }
41: 
42:     public function grant(Node|int|string $node, GrantInput $grant): NodeGrant
43:     {
44:         $node = $this->located($node);
45:         $this->guard->authorizeGrants(context: $this->context, node: $node);
46: 
47:         return $this->create->execute(context: $this->context, input: new GrantNodeAccessInput(node: $node, grant: $grant));
48:     }
49: 
50:     public function revoke(Node|int|string $node, NodeGrant|int|string $grant): void
51:     {
52:         $node = $this->located($node);
53:         $this->guard->authorizeGrants(context: $this->context, node: $node);
54:         $class = $this->config->model('grant');
55:         $key = $grant instanceof NodeGrant ? $grant->getRawOriginal($grant->getKeyName()) : $grant;
56:         $row = $class::query()->where('node_id', $node->id)->whereKey($key)->first() ?? throw NodeNotFoundException::forId($node->id);
57:         $this->delete->execute(context: $this->context, input: new RevokeGrantInput(node: $node, grant: $row));
58:     }
59: 
60:     public function explain(Node|int|string $node, Ability $ability, ?Authorizable $subject = null): AccessExplanation
```

## packages/core/tests/Arch/OperationsBoundaryTest.php

Source: [vaulter/packages/core/tests/Arch/OperationsBoundaryTest.php](/home/vostrikov/projects/packages/vaulter/packages/core/tests/Arch/OperationsBoundaryTest.php); SHA-256 `2f1a8068413e9dc7bbac2aedf5d03f573635be19009e648e76cac203b8b4dc42`.

Lines 11–23:

```text
11: arch('migrated core controllers use Operations rather than Actions or Repositories')
12:     ->expect([
13:         GrantController::class,
14:         ShareController::class,
15:         ActivityController::class,
16:     ])->not->toUse(['Vaulter\Actions', 'Vaulter\Repositories']);
17: 
18: arch('core access share and activity Operations have no drive package dependency')
19:     ->expect([
20:         AccessOperations::class,
21:         ShareOperations::class,
22:         ActivityOperations::class,
23:     ])->not->toUse('Vaulter\Drive');
```

## packages/core/src/Exceptions/VaulterException.php

Source: [vaulter/packages/core/src/Exceptions/VaulterException.php](/home/vostrikov/projects/packages/vaulter/packages/core/src/Exceptions/VaulterException.php); SHA-256 `4d14300c9d0d8c82ca021ddb0a70bb7a0eda364c6cc33584a8f40df3ee006357`.

Lines 13–31:

```text
13: /**
14:  * Base for every Vaulter error — catch this to handle all package failures.
15:  */
16: class VaulterException extends RuntimeException
17: {
18:     public function render(Request $request): JsonResponse|false
19:     {
20:         if (!$this instanceof ProblemException) {
21:             return false;
22:         }
23: 
24:         if (!$request->expectsJson() && !$request->is(trim(app(RoutesConfig::class)->prefix(), '/').'/*')) {
25:             return false;
26:         }
27: 
28:         return new JsonResponse(
29:             ['message' => $this->getMessage(), 'code' => $this->problemCode()] + $this->renderExtras(),
30:             $this->httpStatus(),
31:         );
```

## packages/core/src/Webhooks/OutboxRecorder.php

Source: [vaulter/packages/core/src/Webhooks/OutboxRecorder.php](/home/vostrikov/projects/packages/vaulter/packages/core/src/Webhooks/OutboxRecorder.php); SHA-256 `daf626337a26a95e89214fb7b46f69041d50eca149cdb4cc3f0fd7b433f1123f`.

Lines 17–37:

```text
17: /**
18:  * The SYNCHRONOUS half of the transactional outbox (master plan §2.11 / D14 / M3b),
19:  * ported from chatom's WebhookDispatcher. It maps a known Vaulter domain event into a
20:  * SLIM webhook payload and durably stages it as ONE v_outbox row — idempotent by
21:  * event_id, so a re-fired event never stages a duplicate. The actual HTTP delivery
22:  * (retries, backoff, DLQ, signed POST) is the relay's job.
23:  *
24:  * Kernel domains (Access grants, Sharing links) map their own events here; the Drive
25:  * behaviour package (P2 #5 / ADR-0015) maps its file/folder/upload events through the
26:  * PUBLIC {@see stage()} seam, so core no longer references any Vaulter\Drive\Events\*
27:  * class while the outbox payloads retain their domain fields.
28:  *
29:  * Slim payloads (HARDENING): only ids, names, sizes and enum values travel — never
30:  * blob bytes or secrets (a share's token_hash/secret is never serialized; only the
31:  * non-secret token_prefix). The event_id is STABLE and event-derived so the relay
32:  * and downstream consumers can dedupe at-least-once delivery on it.
33:  *
34:  * Thin side-effect service (store-only): it persists an outbox row and nothing else.
35:  * It makes no business decisions and never accepts a Request. Not final — a host may
36:  * subclass it to enrich payloads, and the subscriber's resilience is test-mockable.
37:  */
```

Lines 129–180:

```text
129:     /**
130:      * Stage ONE outbox row for an event — the PUBLIC seam the kernel handlers above
131:      * and the vaulter-drive subscriber both map through. Idempotent by event_id: when
132:      * a row for this occurrence id already exists (a retried send, a re-fired event), this is a
133:      * no-op and returns null, so the relay never fans the same event out twice.
134:      *
135:      * @param array<string, mixed> $payload
136:      */
137:     public function stage(
138:         EventType $event,
139:         string $eventId,
140:         string $panel,
141:         ?string $topic,
142:         array $payload,
143:         int|string|null $actorId,
144:     ): ?Outbox {
145:         /** @var class-string<Outbox> $outboxClass */
146:         $outboxClass = $this->vaulterConfig->model('outbox');
147: 
148:         if ($outboxClass::query()->where('event_id', $eventId)->exists()) {
149:             return null;
150:         }
151: 
152:         return $this->metrics->timing(
153:             name: 'vaulter_outbox_stage_duration_seconds',
154:             operation: function () use ($outboxClass, $eventId, $panel, $topic, $event, $payload, $actorId): Outbox {
155:                 // The actor id rides inside the payload so consumers see who caused the event
156:                 // without a separate column on the wire.
157:                 $payload['event_id'] = $eventId;
158:                 $payload['actor_id'] = Identity::key($actorId);
159: 
160:                 // forceFill (P1.4/F-P1.8-02): Outbox is intentionally fully guarded — this
161:                 // is the outbox's own trusted staging seam, never raw request input.
162:                 /** @var Outbox $outbox */
163:                 $outbox = new $outboxClass;
164:                 $outbox->forceFill([
165:                     'event_id' => $eventId,
166:                     'profile' => $panel,
167:                     'topic' => $topic,
168:                     'event' => $event->value,
169:                     'payload' => $payload,
170:                     'available_at' => now(),
171:                     'status' => 'pending',
172:                     'attempts' => 0,
173:                 ]);
174:                 $outbox->save();
175: 
176:                 return $outbox;
177:             },
178:         );
179:     }
180: 
```

## packages/azgard/composer.json

Source: [vaulter/packages/azgard/composer.json](/home/vostrikov/projects/packages/vaulter/packages/azgard/composer.json); SHA-256 `b56602ef88523afd6b64664ecd9a8d80ca493ca347f729247f15b0e916c542be`.

Lines 1–7:

```text
1: {
2:     "name": "axioma-studio/vaulter-azgard",
3:     "description": "AzGuard permission bridge for Vaulter — a real AzgardGuard adapter delegating to AzGuard's scoped roles and workspace-context checks, so a host wires its ecosystem authorization into Vaulter's composite ACL with one binding.",
4:     "type": "library",
5:     "license": "MIT",
6:     "keywords": ["laravel", "authorization", "permissions", "azguard", "corex", "vaulter"],
7:     "homepage": "https://github.com/axiomasoft/vaulter",
```

Lines 18–31:

```text
18:     "require": {
19:         "php": "^8.4",
20:         "axioma-studio/vaulter-core": "self.version",
21:         "axioma-studio/azguard-core": "dev-main",
22:         "illuminate/contracts": "^12.0|^13.0",
23:         "illuminate/support": "^12.0|^13.0"
24:     },
25:     "require-dev": {
26:         "axioma-studio/azguard-context": "dev-main",
27:         "larastan/larastan": "^3.0",
28:         "laravel/pint": "^1.13",
29:         "orchestra/testbench": "^10.0|^11.0",
30:         "pestphp/pest": "^4.0",
31:         "pestphp/pest-plugin-laravel": "^4.0"
```

## packages/azgard/src/VaulterAzgardServiceProvider.php

Source: [vaulter/packages/azgard/src/VaulterAzgardServiceProvider.php](/home/vostrikov/projects/packages/vaulter/packages/azgard/src/VaulterAzgardServiceProvider.php); SHA-256 `d462def5ff16ee3ec1e4255e66f8dfca76a935f0a213cf1f75c4b1b705aab29b`.

Lines 15–30:

```text
15: /**
16:  * Wires the AzGuard/Corex permission bridge (master plan P1 #2). It binds the Vaulter
17:  * {@see AzgardGuard} seam to an {@see AzgardGuardAdapter} built from the CURRENT
18:  * request's AzGuard-backed user, so that once the host selects the `azgard` permission
19:  * driver (`vaulter.access.permission_driver = 'azgard'`) the driver resolves real
20:  * AzGuard checks with no further wiring.
21:  *
22:  * When the request has no AzGuard-backed user (a guest, or a User that does not
23:  * implement {@see AzgardBackedUser}) the bound guard is the deny-all
24:  * {@see NullAzgardGuard} — a safe pass-through that raises no access, never a fatal.
25:  *
26:  * The host still owns the ecosystem alignment this bridge cannot do for it: ulid morphs
27:  * (`vaulter.ids.default=ulid` + `corex.ids.strategy=Ulid` + `AZ_GUARD_MORPH_TYPE=ulid`),
28:  * registering workspace/owner types as Corex morph aliases, and adding
29:  * `implements AzgardBackedUser` (satisfied for free by AzGuard's HasAzGuard +
30:  * HasScopedRoles traits) to its User model.
```

Lines 34–57:

```text
34:     public function register(): void
35:     {
36:         $this->mergeConfigFrom(__DIR__.'/../config/vaulter-azgard.php', 'vaulter-azgard');
37: 
38:         $this->app->singleton(AzgardConfig::class, fn (Container $app): AzgardConfig => new AzgardConfig($app->make('config')));
39:         $this->app->singletonIf(ConfigNormalizer::class);
40:         $this->app->make(ConfigNormalizer::class)->registerDefaults(root: 'vaulter-azgard', path: __DIR__.'/../config/vaulter-azgard.php');
41: 
42:         if (!$this->app->make('config')->has('vaulter.access.permission_drivers.azgard')) {
43:             $this->app->make('config')->set('vaulter.access.permission_drivers.azgard', AzgardPermissionDriver::class);
44:         }
45: 
46:         $this->app->bind(AzgardGuard::class, static function (Container $app): AzgardGuard {
47:             $user = $app->make(AuthFactory::class)->guard()->user();
48: 
49:             if (!$user instanceof AzgardBackedUser) {
50:                 return new NullAzgardGuard;
51:             }
52: 
53:             return new AzgardGuardAdapter(
54:                 user: $user,
55:                 panelId: $app->make(AzgardConfig::class)->panelId(),
56:             );
57:         });
```

## docs/guide/database-compatibility.md

Source: [vaulter/docs/guide/database-compatibility.md](/home/vostrikov/projects/packages/vaulter/docs/guide/database-compatibility.md); SHA-256 `d94ccf3a6be7b0692daad90476707470110c76c745d9184d86aad9688e938604`.

Lines 9–12:

```text
9: Vaulter is **Postgres-first, portable to MySQL, and runs on SQLite**. The schema is one
10: shared `v_*` set of tables (see [Storage Panels](/guide/storage-panels)); nothing about it
11: is engine-locked, but a few features light up only on a real server. This page states what
12: each engine gives you and the handful of Postgres specifics the schema depends on.
```

Lines 30–47:

```text
30: 
31: | Engine | Head integrity |
32: |---|---|
33: | **PostgreSQL**, **MySQL** | Composite foreign keys `v_files_head_fk` / `v_documents_head_fk`: `(current_revision_id, id) → revisions(id, file_id \| document_id) ON DELETE RESTRICT`. A foreign head and deleting a head are refused by the database. |
34: | **SQLite** | No head foreign key: SQLite cannot add a constraint to a table created before its revisions table. The Vaulter Actions (and the head-integrity tests) keep the invariant; writes that bypass the Actions are not checked. |
35: 
36: Purge releases the head before the node row is deleted: MySQL checks the RESTRICT key while the
37: node → aggregate → revision cascade runs, before the aggregate row is gone. Tables and columns
38: are not a public API — change Vaulter data only through its PHP API.
39: 
40: ## Supported engines
41: 
42: | Engine | Status | Use it for |
43: |---|---|---|
44: | **PostgreSQL 14+** | **Recommended.** The full test suite runs against Postgres in CI across every id strategy. | Production. Everything below works natively. |
45: | **SQLite** | Fully supported. The default test lane runs on in-memory SQLite. | Local development, tests, small single-file installs. |
46: | **MySQL 8+** | Supported. Live siblings are unique by a stored `name_key_hash` (sha2 of the binary `name_key`, NULL when trashed); a functional unique index enforces the single drive root; cascading foreign keys are preserved. | Production where Postgres-only locking and index features are not required. |
47: | **MariaDB** | Host-key DDL is tested. A complete fresh installation is not guaranteed: the `mariadb` driver still guards live siblings and the single drive root with STORED generated columns over `parent_id`, the pattern that conflicts with cascading foreign keys on MySQL. The complete suite is not part of the required database matrix. | Evaluate against your target MariaDB version before production use. |
```
