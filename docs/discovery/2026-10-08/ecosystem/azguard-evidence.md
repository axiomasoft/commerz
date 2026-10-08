# azguard — source evidence

HEAD `ba02a9a422aa76d20333129b430861ca052258d4`; dirty: `True`; working-tree snapshot.

## AGENTS.md

Source: [azguard/AGENTS.md](/home/vostrikov/projects/packages/azguard/AGENTS.md); SHA-256 `fea958df80fb34956cedb44623e0fc5ba62e945c83625235c5a276e4239278fd`.

Lines 77–90:

```text
77: <!-- semantic:root.codex.bootstrap -->
78: <!-- semantic:root.codex.launch-and-hook-integrity -->
79: <!-- semantic:root.codex.fresh-agent-execution -->
80: <!-- semantic:root.codex.package-scope-routing -->
81: <!-- instruction:root.codex.bootstrap -->
82: ## root.codex.bootstrap
83: Treat `.agents/skills/*` as thin generated Codex bootstrap projections that are never the source of truth: canonical skill and command content stays under `packages/skiller/skills/` and `packages/task/commands/`.
84: <!-- instruction:root.codex.launch-and-hook-integrity -->
85: ## root.codex.launch-and-hook-integrity
86: For a Codex launch, name the intended cwd, routed model/effort, `-a never` and `-s danger-full-access`. Check cwd and working-tree state before edits. Run doctor, hook/settings integrity and maind health on installation, environment changes or symptoms, not every code task. Treat hook exit `127` as a path defect and repair its effective registration. A runnable plan handoff includes provider-neutral Next and the native launch block; an ordinary completed task needs no new launch.
87: <!-- instruction:root.codex.fresh-agent-execution -->
88: ## root.codex.fresh-agent-execution
89: For an active executable plan, use the committed handoff and typed continuity. Continue in the current root when context and capabilities suffice; delegate for useful independent work or required independent review. Fix regressions through the owning item; escalate only a real authority or frozen-contract change. Respect a separately requested design/review boundary.
90: <!-- instruction:root.codex.package-scope-routing -->
```

## packages/core/composer.json

Source: [azguard/packages/core/composer.json](/home/vostrikov/projects/packages/azguard/packages/core/composer.json); SHA-256 `e75629a48fa8d942592985809c85d6d881b264121a5cf39d78d1a2ad76042b2d`.

Lines 1–57:

```text
1: {
2:     "name": "axiomasoft/azguard",
3:     "description": "Panel-based authorization for Laravel: permission sources, explicit policy authority, scoped assignments",
4:     "type": "library",
5:     "license": "MIT",
6:     "keywords": [
7:         "laravel",
8:         "authorization",
9:         "permissions",
10:         "roles",
11:         "rbac",
12:         "access-control",
13:         "gate"
14:     ],
15:     "homepage": "https://github.com/axiomasoft/azguard",
16:     "authors": [
17:         {
18:             "name": "Dmitry Vostrikov",
19:             "email": "dv.vostrikov@gmail.com"
20:         }
21:     ],
22:     "support": {
23:         "issues": "https://github.com/axiomasoft/azguard/issues",
24:         "source": "https://github.com/axiomasoft/azguard"
25:     },
26:     "require": {
27:         "php": "^8.3",
28:         "illuminate/auth": "^11.0|^12.0|^13.0",
29:         "illuminate/console": "^11.0|^12.0|^13.0",
30:         "illuminate/contracts": "^11.0|^12.0|^13.0",
31:         "illuminate/database": "^11.0|^12.0|^13.0",
32:         "illuminate/filesystem": "^11.0|^12.0|^13.0",
33:         "illuminate/http": "^11.0|^12.0|^13.0",
34:         "illuminate/log": "^11.0|^12.0|^13.0",
35:         "illuminate/queue": "^11.0|^12.0|^13.0",
36:         "illuminate/routing": "^11.0|^12.0|^13.0",
37:         "illuminate/support": "^11.0|^12.0|^13.0"
38:     },
39:     "suggest": {
40:         "dragonmantank/cron-expression": "Required to validate cron expressions and run Laravel scheduled maintenance without laravel/framework."
41:     },
42:     "autoload": {
43:         "psr-4": {
44:             "AzGuard\\": "src/"
45:         }
46:     },
47:     "extra": {
48:         "branch-alias": {
49:             "dev-main": "1.0.x-dev"
50:         },
51:         "laravel": {
52:             "providers": [
53:                 "AzGuard\\AzGuardServiceProvider"
54:             ]
55:         }
56:     }
57: }
```

## packages/core/config/azguard.php

Source: [azguard/packages/core/config/azguard.php](/home/vostrikov/projects/packages/azguard/packages/core/config/azguard.php); SHA-256 `a77e056c8799d941e4268c1ec78985e6657db43b1c0fac24a1a7daa80a515465`.

Lines 32–74:

```text
32:     | A panel takes a setting from its provider, then from its plugins, then
33:     | from AzGuard::configurePanels(), and only then from here. The command
34:     | azguard:panels:list --settings shows where each value came from.
35:     |
36:     */
37: 
38:     'defaults' => [
39: 
40:         'models' => [
41:             'role_grant' => RoleGrant::class,
42:             'permission_grant' => PermissionGrant::class,
43:             'permission' => Permission::class,
44:         ],
45: 
46:         // Prefix of permission names: true is the panel id (admin.orders.view),
47:         // false turns it off. A custom prefix is set on the panel.
48:         'resource_prefix' => true,
49: 
50:         'gate' => [
51:             // authoritative: the answer for an ability the panel owns is final.
52:             'mode' => 'authoritative',
53:         ],
54: 
55:         'cache' => [
56:             // A Laravel cache store; null keeps permission sets for the request only.
57:             'store' => null,
58:             'ttl' => 3600,
59:             'generation' => 1,
60:         ],
61: 
62:         'consistency' => [
63:             'reads' => 'primary',           // primary | default
64:             'state_refresh' => 'request',   // request | check
65:         ],
66: 
67:         // Dispatch a decision event on every check, for diagnostics.
68:         'trace_decisions' => false,
69: 
70:         // Resolvers every panel tries after its own, in this order. A panel names the tenant and the assignment
71:         // scope of a request with its own resolvers first; these are class names, never closures.
72:         'tenants' => ['resolvers' => []],
73: 
74:         'scopes' => ['resolvers' => []],
```

Lines 141–149:

```text
141:     'storages' => [
142:         'default' => [
143:             'connection' => env('AZGUARD_DB_CONNECTION'),
144:             'table_prefix' => 'azg_',
145:             'host_keys' => null,
146:         ],
147:     ],
148: 
149:     'ids' => ['host_keys' => 'string'],
```

## packages/core/src/Storage/Schema/StorageSchema.php

Source: [azguard/packages/core/src/Storage/Schema/StorageSchema.php](/home/vostrikov/projects/packages/azguard/packages/core/src/Storage/Schema/StorageSchema.php); SHA-256 `f9b36c16c5c7d85a2452469f67edacdd71a937dd4abfa9ff6f0e7c1af656f697`.

Lines 11–61:

```text
11: /**
12:  * Creates and drops the complete schema of a configured AzGuard storage.
13:  *
14:  * @api
15:  */
16: final readonly class StorageSchema
17: {
18:     public function __construct(private StorageRegistry $storages) {}
19: 
20:     public function create(string $storage): void
21:     {
22:         $storage = $this->storages->get($storage);
23:         $schema = $storage->connection()->getSchemaBuilder();
24:         $prefix = $storage->prefix();
25:         $driver = $storage->connection()->getDriverName();
26:         $schema->create($prefix.'permissions', function (Blueprint $table) use ($storage, $prefix, $driver): void {
27:             $table->bigIncrements('id');
28:             HostKeyColumns::identifier($table, 'panel', 64, $driver);
29:             $this->scopeColumns($table, $storage, 'tenant');
30:             HostKeyColumns::identifier($table, 'name', 255, $driver);
31:             $table->string('label', 191)->nullable();
32:             $table->string('group', 191)->nullable();
33:             $table->text('description')->nullable();
34:             $this->metadataColumns($table);
35:             $table->unique(['panel', 'tenant_key', 'name'], $prefix.'pm_identity');
36:         });
37:         $this->scopeConstraints($storage, 'permissions', 'pm', 'tenant');
38:         foreach (['role' => 'rg', 'permission' => 'pg'] as $identity => $short) {
39:             $base = $identity.'_grants';
40:             $schema->create($prefix.$base, function (Blueprint $table) use ($storage, $prefix, $driver, $identity, $short): void {
41:                 $table->bigIncrements('id');
42:                 HostKeyColumns::identifier($table, 'panel', 64, $driver);
43:                 $this->scopeColumns($table, $storage, 'tenant');
44:                 HostKeyColumns::identifier($table, $identity, $identity === 'role' ? 64 : 255, $driver);
45:                 HostKeyColumns::identifier($table, 'subject_type', 128, $driver);
46:                 HostKeyColumns::hostKey($table, 'subject_id', $storage->hostKeys(), $driver);
47:                 $this->scopeColumns($table, $storage, 'context');
48:                 HostKeyColumns::identifier($table, 'origin', 128, $driver)->default('manual');
49:                 $table->dateTime('expires_at')->nullable();
50:                 HostKeyColumns::identifier($table, 'actor_type', 128, $driver)->nullable();
51:                 HostKeyColumns::hostKey($table, 'actor_id', $storage->hostKeys(), $driver)->nullable();
52:                 $table->text('actor_reason')->nullable();
53:                 $this->metadataColumns($table);
54:                 $table->unique(['panel', 'tenant_key', $identity, 'subject_type', 'subject_id', 'context_key', 'origin'], $prefix.$short.'_identity');
55:                 $table->index(['panel', 'tenant_key', 'subject_type', 'subject_id', 'context_key'], $prefix.$short.'_subject');
56:                 $table->index(['panel', 'tenant_key', 'context_type', 'context_id'], $prefix.$short.'_context');
57:                 $table->index(['panel', 'tenant_key', 'origin'], $prefix.$short.'_origin');
58:                 $table->index('expires_at', $prefix.$short.'_expiry');
59:             });
60:             $this->scopeConstraints($storage, $base, $short, 'tenant');
61:             $this->scopeConstraints($storage, $base, $short, 'context');
```

Lines 83–119:

```text
83:         $schema->create($prefix.'panel_state', function (Blueprint $table) use ($prefix, $driver): void {
84:             HostKeyColumns::identifier($table, 'panel', 64, $driver);
85:             $table->primary('panel', $prefix.'ps_pk');
86:             $table->bigInteger('version')->default(0);
87:             HostKeyColumns::identifier($table, 'incarnation', 26, $driver);
88:             $table->dateTime('updated_at');
89:         });
90:         $schema->create($prefix.'storage_state', function (Blueprint $table) use ($prefix): void {
91:             $table->smallInteger('id');
92:             $table->primary('id', $prefix.'ss_pk');
93:             $table->json('schema');
94:         });
95:         $this->constraint($storage, 'storage_state', 'ss_singleton', 'id = 1', ['id']);
96:         $storage->table('storage_state')->insert(['id' => 1, 'schema' => json_encode($storage->schema(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
97:     }
98: 
99:     public function drop(string $storage): void
100:     {
101:         $storage = $this->storages->get($storage);
102:         foreach (['audit_log', 'permission_grants', 'role_grants', 'permissions', 'panel_state', 'storage_state'] as $base) {
103:             $storage->connection()->getSchemaBuilder()->dropIfExists($storage->prefix().$base);
104:         }
105:     }
106: 
107:     private function scopeColumns(Blueprint $table, Storage $storage, string $scope): void
108:     {
109:         $driver = $storage->connection()->getDriverName();
110:         HostKeyColumns::identifier($table, $scope.'_key', 200, $driver);
111:         HostKeyColumns::identifier($table, $scope.'_type', 128, $driver)->nullable();
112:         HostKeyColumns::hostKey($table, $scope.'_id', $storage->hostKeys(), $driver)->nullable();
113:     }
114: 
115:     private function metadataColumns(Blueprint $table): void
116:     {
117:         $table->json('meta')->nullable();
118:         $table->dateTime('created_at')->nullable();
119:         $table->dateTime('updated_at')->nullable();
```

Lines 122–151:

```text
122:     private function scopeConstraints(Storage $storage, string $base, string $short, string $scope): void
123:     {
124:         $key = $scope.'_key';
125:         $type = $scope.'_type';
126:         $id = $scope.'_id';
127:         $this->constraint($storage, $base, $short.'_'.$scope.'_pair',
128:             "(($key = 'global') = ($type IS NULL AND $id IS NULL)) AND (($type IS NULL) = ($id IS NULL))", [$key, $type, $id]);
129:     }
130: 
131:     /** @param list<string> $columns */
132:     private function constraint(Storage $storage, string $base, string $name, string $predicate, array $columns): void
133:     {
134:         $connection = $storage->connection();
135:         $grammar = $connection->getQueryGrammar();
136:         $table = $grammar->wrapTable($storage->prefix().$base);
137: 
138:         if ($connection->getDriverName() !== 'sqlite') {
139:             $name = $grammar->wrap($storage->prefix().$name);
140:             $connection->statement("ALTER TABLE $table ADD CONSTRAINT $name CHECK ($predicate)");
141: 
142:             return;
143:         }
144:         $newPredicate = $predicate;
145:         foreach ($columns as $column) {
146:             $newPredicate = str_replace($column, 'NEW.'.$column, $newPredicate);
147:         }
148:         foreach (['insert' => 'INSERT', 'update' => 'UPDATE OF '.implode(', ', $columns)] as $suffix => $operation) {
149:             $trigger = $grammar->wrap($storage->prefix().$name.'_'.$suffix);
150:             $connection->statement("CREATE TRIGGER $trigger BEFORE $operation ON $table WHEN NOT ($newPredicate) BEGIN SELECT RAISE(ABORT, 'azguard: $name'); END");
151:         }
```

## packages/core/src/Storage/StorageRegistry.php

Source: [azguard/packages/core/src/Storage/StorageRegistry.php](/home/vostrikov/projects/packages/azguard/packages/core/src/Storage/StorageRegistry.php); SHA-256 `1152afac8a3eba50437384d61f6ab7183d919f6aafcc11942b4d51780608913e`.

Lines 18–59:

```text
18:     public function __construct(AzGuardConfig $config, private readonly DatabaseManager $database)
19:     {
20:         foreach ($config->storages() as $id => $parameters) {
21:             $this->register(new Storage($id, $database->connection($parameters['connection']), $parameters['table_prefix'], $parameters['host_keys']));
22:         }
23:     }
24: 
25:     public function get(string $name): Storage
26:     {
27:         return $this->storages[$name] ?? throw InvalidConfigurationException::failing('storage', 'Unknown storage '.$name.'.');
28:     }
29: 
30:     public function own(?string $connection = null, string $prefix = 'azg_', string $hostKeys = 'string'): Storage
31:     {
32:         $resolved = $this->database->connection($connection);
33:         $name = $resolved->getName() ?? throw InvalidConfigurationException::failing('storage', 'Storage requires a named connection.');
34:         $id = 'own-'.substr(hash('sha256', IdentityCodec::compose([$name, $prefix])), 0, 60);
35:         $storage = new Storage($id, $resolved, $prefix, $hostKeys);
36: 
37:         foreach ($this->storages as $existing) {
38:             if ($existing->connectionName() === $name && $existing->prefix() === $prefix) {
39:                 if ($existing->hostKeys() !== $hostKeys) {
40:                     throw InvalidConfigurationException::failing('host_keys', 'Storage connection/prefix is already registered with different host keys.');
41:                 }
42: 
43:                 return $existing;
44:             }
45:         }
46:         $this->register($storage);
47: 
48:         return $storage;
49:     }
50: 
51:     public function register(Storage $storage): void
52:     {
53:         foreach ($this->storages as $existing) {
54:             if ($existing->id() === $storage->id()
55:                 || ($existing->connectionName() === $storage->connectionName() && $existing->prefix() === $storage->prefix())) {
56:                 throw new StorageMismatchException('Storage '.$storage->id().' names the physical storage of '.$existing->id().' (same connection and table prefix) or repeats its name.');
57:             }
58:         }
59:         $this->storages[$storage->id()] = $storage;
```

## packages/core/src/Storage/Storage.php

Source: [azguard/packages/core/src/Storage/Storage.php](/home/vostrikov/projects/packages/azguard/packages/core/src/Storage/Storage.php); SHA-256 `f57ecbf07fe9c6154690e0ff4327f076549407b83b3fdce696e5fe8564842918`.

Lines 55–65:

```text
55:     public function __construct(private readonly string $id, private readonly Connection $connection,
56:         private readonly string $prefix = 'azg_', private readonly string $hostKeys = 'string')
57:     {
58:         if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $id) !== 1
59:             || preg_match('/\A([a-z][a-z0-9_]{0,19})?\z/', $prefix) !== 1) {
60:             throw InvalidConfigurationException::failing('storage', 'Invalid storage identity or prefix.');
61:         }
62: 
63:         if (! in_array($hostKeys, ['string', 'bigint', 'uuid', 'ulid'], true)) {
64:             throw InvalidConfigurationException::failing('host_keys', 'Invalid host key type.');
65:         }
```

Lines 175–205:

```text
175:     private function makeModel(string $kind, ?string $class): Model
176:     {
177:         [$base, $table] = match ($kind) {
178:             'role_grant' => [RoleGrant::class, 'role_grants'],
179:             'permission_grant' => [PermissionGrant::class, 'permission_grants'],
180:             'permission' => [Permission::class, 'permissions'],
181:             default => throw new StorageMismatchException('Unknown storage model kind '.$kind.'.'),
182:         };
183:         $class ??= app(AzGuardConfig::class)->defaultModels()[$kind];
184: 
185:         if (! is_a($class, $base, true)) {
186:             throw new StorageMismatchException('Storage model '.$class.' must extend '.$base.'.');
187:         }
188:         $reflection = new ReflectionClass($class);
189: 
190:         if (! $reflection->isInstantiable()) {
191:             throw new StorageMismatchException('Storage model '.$class.' must be concrete.');
192:         }
193:         $expectedTable = $this->prefix.$table;
194:         $defaults = $reflection->getDefaultProperties();
195:         $this->assertModelSetting($class, 'table', $defaults['table'] ?? null, $expectedTable);
196:         $this->assertModelSetting($class, 'connection', $defaults['connection'] ?? null, $this->connectionName());
197: 
198:         do {
199:             $this->assertModelAttributes($class, $reflection, $expectedTable);
200:             foreach ($reflection->getTraits() as $trait) {
201:                 $this->assertModelAttributes($class, $trait, $expectedTable);
202:             }
203:         } while (($reflection = $reflection->getParentClass()) !== false);
204: 
205:         return (new $class)->bindToStorage($this, $expectedTable);
```

Lines 244–274:

```text
244:     public function mutate(string|array $panels, Closure $work, int $attempts = 3): mixed
245:     {
246:         $panels = is_string($panels) ? [$panels] : $panels;
247:         foreach ($panels as $panel) {
248:             PermissionGrammar::assertPanelId($panel);
249:         }
250:         $panels = array_values(array_unique($panels));
251:         sort($panels, SORT_STRING);
252: 
253:         if ($attempts < 1 || $panels === []) {
254:             throw new InvalidArgumentException('Mutation needs panels and at least one attempt.');
255:         }
256:         $this->assertSchema();
257:         $nested = $this->connection->transactionLevel() > 0;
258:         for ($attempt = 1; ; $attempt++) {
259:             $committed = false;
260: 
261:             try {
262:                 return $this->connection->transaction(function () use ($panels, $work, $nested, &$committed): mixed {
263:                     $this->connection->afterCommit(static function () use (&$committed): void {
264:                         $committed = true;
265:                     });
266: 
267:                     return $this->perform($panels, $work, $nested);
268:                 }, 1);
269:             } catch (Throwable $error) {
270:                 if ($committed || $nested || $attempt >= $attempts || ! $this->causedByConcurrencyError($error)) {
271:                     throw $error;
272:                 }
273:                 usleep(random_int(5, 25) * $attempt * 1000);
274:             }
```

Lines 378–431:

```text
378:     public function schema(): array
379:     {
380:         return ['version' => 1, 'identity_codec' => IdentityCodec::VERSION,
381:             'storage_id' => $this->id, 'prefix' => $this->prefix, 'host_keys' => $this->hostKeys];
382:     }
383: 
384:     private function assertSchema(): void
385:     {
386:         if ($this->schemaChecked) {
387:             return;
388:         }
389:         $this->verifySchema();
390:         $this->schemaChecked = true;
391:     }
392: 
393:     /**
394:      * Compares the stored `storage_state` with the configuration of this storage now, whatever an earlier check found.
395:      *
396:      * @internal read by the doctor
397:      *
398:      * @throws StorageMismatchException when the state differs or cannot be read; a read failure is the previous exception
399:      */
400:     public function verifySchema(): void
401:     {
402:         $expected = $this->schema();
403:         $found = null;
404: 
405:         try {
406:             $row = $this->table('storage_state')->where('id', 1)->first();
407: 
408:             if ($row !== null) {
409:                 $found = json_decode($row->schema, true, flags: JSON_THROW_ON_ERROR);
410:             }
411:         } catch (QueryException|JsonException $error) {
412:             throw new StorageMismatchException('Storage '.$this->id.' expected '.json_encode($expected).'; cannot read storage_state: '.$error->getMessage(), 0, $error);
413:         }
414: 
415:         if (! is_array($found) || count($found) !== count($expected)
416:             || array_filter($expected, static fn (int|string $value, string $key): bool => ($found[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH) !== []) {
417:             throw new StorageMismatchException('Storage '.$this->id.' expected '.json_encode($expected).'; found '.json_encode($found).'.');
418:         }
419:     }
420: 
421:     private function lockPanel(string $panel): PanelState
422:     {
423:         $query = $this->table('panel_state')->where('panel', $panel);
424:         $row = $this->connection->getDriverName() === 'sqlite' ? null : $query->lockForUpdate()->first();
425: 
426:         if ($row === null) {
427:             $this->table('panel_state')->insertOrIgnore(['panel' => $panel, 'version' => 0,
428:                 'incarnation' => strtolower((string) Str::ulid()), 'updated_at' => gmdate('Y-m-d H:i:s')]);
429:             $row = $query->lockForUpdate()->firstOrFail();
430:         }
431: 
```

## packages/core/src/Kernel/Identity/TenantRef.php

Source: [azguard/packages/core/src/Kernel/Identity/TenantRef.php](/home/vostrikov/projects/packages/azguard/packages/core/src/Kernel/Identity/TenantRef.php); SHA-256 `4231fc7b5d93420ac636167bbd96cd68f0cf4cdefbb780faec2ee334e947975d`.

Lines 9–45:

```text
9: /**
10:  * A tenant: the data boundary of an organization, or the global tenant.
11:  *
12:  * `global()` is a separate form with key `global`; `of('global', ...)` is an ordinary reference.
13:  */
14: final readonly class TenantRef
15: {
16:     private const string GLOBAL_KEY = 'global';
17: 
18:     private function __construct(
19:         private ?string $type,
20:         private ?string $id,
21:     ) {}
22: 
23:     /**
24:      * @throws InvalidIdentityException
25:      */
26:     public static function of(string $type, int|string $id): self
27:     {
28:         IdentityCodec::assertTypeAlias($type, InvalidIdentityException::class);
29: 
30:         return new self($type, IdentityCodec::canonicalId($id, InvalidIdentityException::class));
31:     }
32: 
33:     public static function global(): self
34:     {
35:         return new self(null, null);
36:     }
37: 
38:     public function isGlobal(): bool
39:     {
40:         return $this->type === null;
41:     }
42: 
43:     public function key(): string
44:     {
45:         return $this->type === null ? self::GLOBAL_KEY : $this->type.':'.$this->id;
```

## packages/core/src/Kernel/Identity/SubjectRef.php

Source: [azguard/packages/core/src/Kernel/Identity/SubjectRef.php](/home/vostrikov/projects/packages/azguard/packages/core/src/Kernel/Identity/SubjectRef.php); SHA-256 `3b12b87c4929cb038fce9a96b599e971a8be29af2aaf6a70221c9f2306cb0978`.

Lines 9–27:

```text
9: /**
10:  * The subject a decision is about: a type alias of its identity domain and a canonical id.
11:  */
12: final readonly class SubjectRef
13: {
14:     private function __construct(
15:         private string $type,
16:         private string $id,
17:     ) {}
18: 
19:     /**
20:      * @throws InvalidIdentityException
21:      */
22:     public static function of(string $type, int|string $id): self
23:     {
24:         IdentityCodec::assertTypeAlias($type);
25: 
26:         return new self($type, IdentityCodec::canonicalId($id));
27:     }
```

## packages/core/src/Kernel/Identity/AccessScope.php

Source: [azguard/packages/core/src/Kernel/Identity/AccessScope.php](/home/vostrikov/projects/packages/azguard/packages/core/src/Kernel/Identity/AccessScope.php); SHA-256 `ff3bbce6d4f46992d81f0805a49af3f453a7a3fc515142ce896f553221f48503`.

Lines 7–27:

```text
7: /**
8:  * Where an assignment applies: a tenant and an assignment scope inside it.
9:  *
10:  * The global scope inside a tenant means tenant-wide; it never equals the global tenant.
11:  */
12: final readonly class AccessScope
13: {
14:     private function __construct(
15:         public TenantRef $tenant,
16:         public AssignmentScopeRef $context,
17:     ) {}
18: 
19:     public static function in(TenantRef $tenant, ?AssignmentScopeRef $context = null): self
20:     {
21:         return new self($tenant, $context ?? AssignmentScopeRef::global());
22:     }
23: 
24:     public function equals(self $other): bool
25:     {
26:         return $this->tenant->equals($other->tenant) && $this->context->equals($other->context);
27:     }
```

## packages/core/src/Panels/PanelRegistry.php

Source: [azguard/packages/core/src/Panels/PanelRegistry.php](/home/vostrikov/projects/packages/azguard/packages/core/src/Panels/PanelRegistry.php); SHA-256 `7fef202ff51f7ba5143e53854ecda66659aef96480fac60e82e7b034bbec67a8`.

Lines 30–35:

```text
30: /**
31:  * Collects panel providers and adjustments while the application boots, then compiles every panel and its catalog
32:  * once, freezes and boots the plugins of the panels.
33:  *
34:  * @phpstan-import-type PanelEntry from CatalogCache
35:  */
```

Lines 126–162:

```text
126:     public function register(string $providerClass): void
127:     {
128:         $this->assertOpen('register a panel');
129:         $id = $this->idOf($providerClass);
130: 
131:         if (isset($this->providers[$id])) {
132:             throw new DuplicatePanelException(
133:                 'Panel "'.$id.'" is already registered by '.$this->providers[$id].'; '.$providerClass
134:                 .' cannot register it again. Use replace() to swap the provider.',
135:             );
136:         }
137: 
138:         $this->providers[$id] = $providerClass;
139:     }
140: 
141:     public function replace(string $providerClass): void
142:     {
143:         $this->assertOpen('replace a panel');
144: 
145:         $this->replacements[$this->idOf($providerClass)] = $providerClass;
146:     }
147: 
148:     public function configure(string $id, Closure $callback): void
149:     {
150:         $this->assertOpen('configure a panel');
151:         $this->configure[$id][] = $callback;
152:     }
153: 
154:     public function configureAll(Closure $callback): void
155:     {
156:         $this->assertOpen('configure panels');
157:         $this->configureAll[] = $callback;
158:     }
159: 
160:     public function isFrozen(): bool
161:     {
162:         return $this->frozen;
```

Lines 165–178:

```text
165:     /**
166:      * Compiles every registered panel and freezes the registry; runs once, when the application has booted.
167:      *
168:      * A panel takes its catalog from the catalog cache when the cache was written by this build for the same recipe,
169:      * otherwise from its sources. A failed compilation leaves the registry unfrozen and without panels. Plugins boot
170:      * once the registry is frozen, in the order they were attached.
171:      *
172:      * @throws DefinitionException
173:      */
174:     public function freeze(): void
175:     {
176:         if ($this->frozen) {
177:             return;
178:         }
```

## packages/core/src/Contracts/Plugins/Plugin.php

Source: [azguard/packages/core/src/Contracts/Plugins/Plugin.php](/home/vostrikov/projects/packages/azguard/packages/core/src/Contracts/Plugins/Plugin.php); SHA-256 `8871d81c65f7df7bdeac7dfc553e768ecaa17ced3712d297334cae27e2ebaf6b`.

Lines 11–36:

```text
11: /**
12:  * A ready set of additions to a panel: sources, permissions, roles, restrictions, pipes and health checks attached
13:  * with one call.
14:  *
15:  * A plugin takes its settings through its own constructor or named factory with typed parameters. Every panel
16:  * works with its own copy of the plugin, so one plugin can be attached to several panels.
17:  *
18:  * @spi
19:  */
20: interface Plugin
21: {
22:     /**
23:      * Stable id of the plugin such as `acme/audit-trail`; a panel never has two plugins with one id.
24:      */
25:     public function id(): string;
26: 
27:     /**
28:      * Adds to the panel; runs once per panel in the order the plugins were attached.
29:      */
30:     public function register(PanelBuilder $panel, PluginContext $context): void;
31: 
32:     /**
33:      * Runs once per panel when every panel is compiled; the panel can no longer be changed here.
34:      */
35:     public function boot(Panel $panel, PluginContext $context): void;
36: }
```

## packages/core/src/Plugins/PluginContext.php

Source: [azguard/packages/core/src/Plugins/PluginContext.php](/home/vostrikov/projects/packages/azguard/packages/core/src/Plugins/PluginContext.php); SHA-256 `3569135892ad5c7cf63e7960fd311edf4ab0a574b1c94d3f6ae9e3ebf25f068c`.

Lines 7–27:

```text
7: /**
8:  * What a plugin is told about the build it takes part in: the panel, its own id, the build and its declared
9:  * dependencies.
10:  *
11:  * It carries no options, no container and no panel object: a plugin keeps its settings in its own typed fields.
12:  *
13:  * @api
14:  */
15: final readonly class PluginContext
16: {
17:     /**
18:      * @internal built by the panel compiler
19:      *
20:      * @param  list<string>  $dependencies
21:      */
22:     public function __construct(
23:         private string $panelId,
24:         private string $pluginId,
25:         private string $buildId,
26:         private array $dependencies,
27:     ) {}
```

## packages/core/src/Panels/PanelCompiler.php

Source: [azguard/packages/core/src/Panels/PanelCompiler.php](/home/vostrikov/projects/packages/azguard/packages/core/src/Panels/PanelCompiler.php); SHA-256 `21915e6a1439e61f8a4d2b275437f4b9fa6c3648a7709787ec1e571e442b2528`.

Lines 73–148:

```text
73:     /**
74:      * Runs `register()` of every plugin of the panel and seals the recipe.
75:      *
76:      * Plugins of the panel provider register first, then the ones `configure` for all panels attaches unless the
77:      * panel keeps them off, then the ones a plugin attached while it registered, until no new plugin appears. A
78:      * plugin registers on its own copy, so what it keeps while registering on one panel is not seen on another.
79:      *
80:      * @return list<Attached> plugins of the panel in the order they were attached
81:      *
82:      * @throws DefinitionException when a plugin cannot be created or its id is malformed
83:      * @throws PluginConflictException when two plugins of the panel share an id
84:      * @throws PluginDependencyMissingException
85:      */
86:     public function register(PanelRecipe $recipe, PanelBuilder $builder, Container $container, string $buildId): array
87:     {
88:         $panel = $recipe->panelId();
89:         $detached = $recipe->withoutPlugins();
90:         $queue = [
91:             ...array_map(static fn (Plugin|string $plugin): array => [$plugin, false], $recipe->plugins(PanelRecipe::PROVIDER)),
92:             ...array_map(static fn (Plugin|string $plugin): array => [$plugin, true], $recipe->plugins(PanelRecipe::CONFIGURE)),
93:         ];
94:         $attached = [];
95:         $nested = 0;
96: 
97:         while ($queue !== []) {
98:             foreach ($queue as [$declared, $detachable]) {
99:                 $plugin = $this->instance($panel, $declared, $container);
100:                 $id = $this->pluginId($panel, $plugin);
101: 
102:                 if ($detachable && in_array($id, $detached, true)) {
103:                     continue;
104:                 }
105: 
106:                 if (isset($attached[$id])) {
107:                     throw new PluginConflictException(
108:                         'Panel "'.$panel.'" has two plugins with the id "'.$id.'" ('.$attached[$id]['plugin']::class.' and '
109:                         .$plugin::class.'): attach the plugin once.',
110:                     );
111:                 }
112: 
113:                 $context = new PluginContext($panel, $id, $buildId, $this->dependencies($panel, $id, $plugin));
114:                 $attached[$id] = ['plugin' => $plugin, 'context' => $context];
115: 
116:                 $recipe->during($recipe->attach($id), static fn () => $plugin->register($builder, $context));
117:             }
118: 
119:             $byPlugins = $recipe->plugins(PanelRecipe::PLUGIN);
120:             $queue = array_map(static fn (Plugin|string $plugin): array => [$plugin, false], array_slice($byPlugins, $nested));
121:             $nested = count($byPlugins);
122:         }
123: 
124:         $recipe->seal();
125: 
126:         foreach ($attached as $id => ['context' => $context]) {
127:             foreach ($context->dependencies() as $dependency) {
128:                 if (! isset($attached[$dependency])) {
129:                     throw new PluginDependencyMissingException(
130:                         'Plugin "'.$id.'" of panel "'.$panel.'" requires the plugin "'.$dependency
131:                         .'", which is not attached to the panel: add it to plugins([...]).',
132:                     );
133:                 }
134:             }
135:         }
136: 
137:         return array_values($attached);
138:     }
139: 
140:     /**
141:      * Runs `boot()` of the plugins of a compiled panel in the order they were attached.
142:      *
143:      * @param  list<Attached>  $plugins
144:      */
145:     public function boot(Panel $panel, array $plugins): void
146:     {
147:         foreach ($plugins as ['plugin' => $plugin, 'context' => $context]) {
148:             $plugin->boot($panel, $context);
```

## packages/core/src/Schema/Field.php

Source: [azguard/packages/core/src/Schema/Field.php](/home/vostrikov/projects/packages/azguard/packages/core/src/Schema/Field.php); SHA-256 `8b2b33a31d9c6c3094324b7931200ab167d1ba9b467395a18bea7219c971e99b`.

Lines 11–74:

```text
11: /** An immutable description of a custom grant column or meta value. */
12: final class Field
13: {
14:     private ?string $label = null;
15: 
16:     private bool $required = false;
17: 
18:     private bool $multiple = false;
19: 
20:     private bool $inMeta = false;
21: 
22:     /** @var list<mixed> */
23:     private array $rules = [];
24: 
25:     private ?string $contribution = null;
26: 
27:     /** @param class-string|null $class */
28:     private function __construct(private readonly string $name, private readonly string $type, private readonly ?string $class = null)
29:     {
30:         if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $name) !== 1
31:             || in_array($name, ['id', 'panel', 'role', 'permission', 'name', 'origin', 'expires_at', 'meta', 'created_at', 'updated_at', 'version'], true)
32:             || preg_match('/\A(tenant|subject|context|actor)_/', $name) === 1) {
33:             throw new DefinitionException('Invalid or reserved grant field name: '.$name.'.');
34:         }
35:     }
36: 
37:     public static function string(string $name): self
38:     {
39:         return new self($name, 'string');
40:     }
41: 
42:     public static function int(string $name): self
43:     {
44:         return new self($name, 'int');
45:     }
46: 
47:     public static function bool(string $name): self
48:     {
49:         return new self($name, 'bool');
50:     }
51: 
52:     public static function date(string $name): self
53:     {
54:         return new self($name, 'date');
55:     }
56: 
57:     /** @param class-string<BackedEnum> $enum */
58:     public static function enum(string $name, string $enum): self
59:     {
60:         if (! is_subclass_of($enum, BackedEnum::class)) {
61:             throw new DefinitionException('Field enum must be a backed enum: '.$enum.'.');
62:         }
63: 
64:         return new self($name, 'enum', $enum);
65:     }
66: 
67:     /** @param class-string<Model> $model */
68:     public static function model(string $name, string $model): self
69:     {
70:         if (! is_subclass_of($model, Model::class)) {
71:             throw new DefinitionException('Field model must be an Eloquent model: '.$model.'.');
72:         }
73: 
74:         return new self($name, 'model', $model);
```

Lines 85–124:

```text
85:     public function required(): self
86:     {
87:         $copy = clone $this;
88:         $copy->required = true;
89: 
90:         return $copy;
91:     }
92: 
93:     public function multiple(): self
94:     {
95:         $copy = clone $this;
96:         $copy->multiple = true;
97: 
98:         return $copy;
99:     }
100: 
101:     /** @param list<mixed> $rules */
102:     public function rules(array $rules): self
103:     {
104:         $copy = clone $this;
105:         $copy->rules = [...$this->rules, ...$rules];
106: 
107:         return $copy;
108:     }
109: 
110:     public function inMeta(): self
111:     {
112:         $copy = clone $this;
113:         $copy->inMeta = true;
114: 
115:         return $copy;
116:     }
117: 
118:     /** @internal Assigned by the panel compiler or the model field collector. */
119:     public function withContribution(string $origin): self
120:     {
121:         $copy = clone $this;
122:         $copy->contribution = $origin;
123: 
124:         return $copy;
```

## packages/core/src/Schema/FieldSchema.php

Source: [azguard/packages/core/src/Schema/FieldSchema.php](/home/vostrikov/projects/packages/azguard/packages/core/src/Schema/FieldSchema.php); SHA-256 `e5a3a6b46881601b510e0016f3ad4c7ff5d6049478730c9c45fedb3c2d70a11d`.

Lines 13–32:

```text
13: /**
14:  * A grant field as an interface shows it: scalars and class names only, never a rule object or a closure.
15:  */
16: final readonly class FieldSchema implements JsonSerializable
17: {
18:     /**
19:      * @param  string  $type  `string`, `int`, `bool`, `date`, `enum` or `model`
20:      * @param  list<string>  $rules  the presence rule, `array` for a list value, `exists` for a model and the declared rules
21:      * @param  array<int|string, string>|null  $options  value => label of the cases of an enum field
22:      * @param  string  $contributedBy  `model:Class`, `provider`, `plugin:id` or `configure`
23:      */
24:     public function __construct(
25:         public string $name,
26:         public string $label,
27:         public string $type,
28:         public array $rules,
29:         public ?array $options,
30:         public bool $inMeta,
31:         public string $contributedBy,
32:     ) {}
```

Lines 63–70:

```text
63:         return new self(
64:             name: $field->name(),
65:             label: $field->getLabel(),
66:             type: $field->type(),
67:             rules: $rules,
68:             options: $options,
69:             inMeta: $field->isInMeta(),
70:             contributedBy: $field->contributedBy() ?? 'panel',
```

## packages/core/src/Contracts/Sources/Source.php

Source: [azguard/packages/core/src/Contracts/Sources/Source.php](/home/vostrikov/projects/packages/azguard/packages/core/src/Contracts/Sources/Source.php); SHA-256 `51bad20aaeb69ea20a271ccef3ef773437f3c4b12db5c09793599639bd6b042e`.

Lines 7–19:

```text
7: /**
8:  * A source of permission definitions and grants attached to a panel; what it provides is declared by the
9:  * capability interfaces it implements.
10:  *
11:  * @spi
12:  */
13: interface Source
14: {
15:     /**
16:      * Stable id of the source inside a panel; two sources of one panel never share it.
17:      */
18:     public function id(): string;
19: }
```

## packages/core/src/Contracts/Sources/StoresGrants.php

Source: [azguard/packages/core/src/Contracts/Sources/StoresGrants.php](/home/vostrikov/projects/packages/azguard/packages/core/src/Contracts/Sources/StoresGrants.php); SHA-256 `4705dc07289d6ec239488088bc4d980c328f0c45a703d980611bb7d1cf30683e`.

Lines 11–33:

```text
11: /**
12:  * The writer of a panel. A panel has at most one.
13:  *
14:  * @spi
15:  */
16: interface StoresGrants extends Source
17: {
18:     /**
19:      * Applies one change that the change pipeline validated, inside the active mutation of the panel. The writer reads
20:      * the stored grant under the lock, compares and writes; a repeat without a difference is `Unchanged`.
21:      */
22:     public function apply(Change $change): ChangeResult;
23: 
24:     /**
25:      * Runs the callback inside the transaction of the source's connection.
26:      *
27:      * @template TResult
28:      *
29:      * @param  Closure(): TResult  $callback
30:      * @return TResult
31:      */
32:     public function transaction(Closure $callback): mixed;
33: }
```

## packages/core/src/Changes/ChangePipeline.php

Source: [azguard/packages/core/src/Changes/ChangePipeline.php](/home/vostrikov/projects/packages/azguard/packages/core/src/Changes/ChangePipeline.php); SHA-256 `9145f8ba57bd2cd75b84d5ec3f9126918d44e86d11badeee35e353878e1ed85a`.

Lines 38–66:

```text
38: /**
39:  * @internal The single write path of grants.
40:  *
41:  * One operation is one panel, one tenant and one writer connection. The writer transaction locks `panel_state` first;
42:  * then the catalog and the stored grants are read again on the mutation's write handle, every stored identity becomes
43:  * its own planned `Change`, each change runs through the `changing` pipes with guarded continuations, the final change
44:  * is validated under the lock and the writer applies it. Any failure rolls back every sibling. A retry of the root
45:  * transaction plans again from fresh reads, clock, frames and pipes.
46:  *
47:  * Events of the effects are built inside the mutation from the writer's results and handed to the root commit: a
48:  * rollback, a retry or an operation without effects delivers nothing, and a listener's failure changes nothing.
49:  */
50: final readonly class ChangePipeline
51: {
52:     public function __construct(private Container $container, private ActingActor $actors, private ChangeJournal $journal, private ChangeEventPublisher $events) {}
53: 
54:     /**
55:      * Grants a role or a permission to the subject in one assignment scope and origin.
56:      *
57:      * @param  array<string, mixed>  $fields
58:      */
59:     public function grant(Panel $panel, TenantRef $tenant, SubjectRef $subject, RoleKey|PermissionPattern $key, AssignmentScopeRef $context,
60:         string $origin = IdentityCodec::DEFAULT_ORIGIN, ?DateTimeImmutable $until = null, array $fields = [], ?ActorRef $actor = null): ChangeResult
61:     {
62:         $scope = AccessScope::in($tenant, $context);
63: 
64:         return $this->run($panel, $tenant, static fn (LockedReads $reads, ?ActorRef $actor): array => [
65:             Change::grant($panel->id(), $scope, $subject, $key, $origin, $actor, $until, $fields),
66:         ], $actor);
```

## packages/core/src/Storage/AuthorityTransaction.php

Source: [azguard/packages/core/src/Storage/AuthorityTransaction.php](/home/vostrikov/projects/packages/azguard/packages/core/src/Storage/AuthorityTransaction.php); SHA-256 `b05de21d2dbcbfdfd2a973006779343226055bdc2e7ff5ba6a28cc34fef018ce`.

Lines 13–53:

```text
13: /** @internal A capability bound to one registered root attempt, handle and execution context. */
14: final class AuthorityTransaction
15: {
16:     private bool $active = true;
17: 
18:     /** @param Fiber<mixed, mixed, mixed, mixed>|null $fiber */
19:     private function __construct(
20:         private readonly Storage $storage,
21:         private readonly DatabaseTransactionsManager $manager,
22:         private readonly DatabaseTransactionRecord $root,
23:         private readonly PDO $pdo,
24:         private readonly ?Fiber $fiber,
25:     ) {}
26: 
27:     public static function begin(Storage $storage): self
28:     {
29:         $manager = app('db.transactions');
30:         $root = $manager->getPendingTransactions()->first(static fn (DatabaseTransactionRecord $record): bool => $record->connection === $storage->connectionName() && $record->level === 1);
31: 
32:         if (! $root instanceof DatabaseTransactionRecord || $storage->connection()->transactionLevel() !== 1) {
33:             throw InvalidConfigurationException::failing('authority_transaction', 'Authority marker requires a registered root transaction.');
34:         }
35: 
36:         return new self($storage, $manager, $root, $storage->connection()->getPdo(), Fiber::getCurrent());
37:     }
38: 
39:     public function assertActive(): void
40:     {
41:         $connection = $this->storage->connection();
42: 
43:         if (! $this->active || $this->fiber !== Fiber::getCurrent() || $connection->transactionLevel() < 1
44:             || $connection->getRawPdo() !== $this->pdo || ! $this->pdo->inTransaction()
45:             || ! $this->manager->getPendingTransactions()->contains(fn (DatabaseTransactionRecord $record): bool => $record === $this->root)) {
46:             throw InvalidConfigurationException::failing('authority_transaction', 'Authority root attempt, PDO or execution context has changed.');
47:         }
48:     }
49: 
50:     public function close(): void
51:     {
52:         $this->active = false;
53:     }
```

## packages/core/src/Changes/ChangeEventPublisher.php

Source: [azguard/packages/core/src/Changes/ChangeEventPublisher.php](/home/vostrikov/projects/packages/azguard/packages/core/src/Changes/ChangeEventPublisher.php); SHA-256 `865765223a18288a8e2cc65ff81b26835a7abf7143e1e74940cd1feba0936904`.

Lines 30–35:

```text
30: /**
31:  * @internal Turns the effects of one change into events and delivers them after the root commit.
32:  *
33:  * `events()` only builds values: it is called inside the mutation, from the values the writer returned, so nothing
34:  * after the commit reads a closed handle or a later state. `dispatch()` runs from the commit callback: one event at a
35:  * time, a listener that throws is reported and the rest are still delivered.
```

Lines 56–68:

```text
56:     /** @param list<AccessEvent> $events */
57:     public function dispatch(array $events): void
58:     {
59:         $dispatcher = $this->container->make(Dispatcher::class);
60:         $observers = $this->container->make(EventObservers::class);
61:         foreach ($events as $event) {
62:             $observers->observe($event);
63: 
64:             try {
65:                 $dispatcher->dispatch($event);
66:             } catch (Throwable $error) {
67:                 report($error);
68:             }
```

## packages/core/src/Exceptions/AzGuardException.php

Source: [azguard/packages/core/src/Exceptions/AzGuardException.php](/home/vostrikov/projects/packages/azguard/packages/core/src/Exceptions/AzGuardException.php); SHA-256 `f24462f82a356e73c19a6b0559d18cd1a622b55df19ddf28449be06abad24268`.

Lines 9–15:

```text
9: /**
10:  * Base of every AzGuard error; `code()` is the stable snake_case machine code.
11:  */
12: abstract class AzGuardException extends RuntimeException
13: {
14:     abstract public function code(): string;
15: }
```

## tests/Arch/ZonesArchTest.php

Source: [azguard/tests/Arch/ZonesArchTest.php](/home/vostrikov/projects/packages/azguard/tests/Arch/ZonesArchTest.php); SHA-256 `551bb10ad9edbfd162d2553f0eb8ddbd483fa6730a45ae9d9545e779c9c19be6`.

Lines 47–74:

```text
47: arch('kernel depends on nothing but PHP')
48:     ->expect('AzGuard\Kernel')
49:     ->not->toUse(['Illuminate', 'Carbon', 'Laravel', ...AZGUARD_OUTER_ZONES]);
50: 
51: const AZGUARD_FRAMEWORK_HELPERS = ['app', 'config', 'now', 'today', 'env', 'resolve', 'request', 'auth', 'cache', 'event', 'logger', 'info'];
52: 
53: it('finds framework helper calls but not methods with the same name', function (): void {
54:     $code = <<<'PHP'
55:         <?php
56:         namespace AzGuard\Kernel\X;
57:         $a = now();
58:         $b = \config('azguard.x');
59:         $c = $object->info();
60:         $d = Clock::now();
61:         $e = new Cache();
62:         function event(): void {}
63:         PHP;
64: 
65:     expect(SourceScan::functionCalls($code, AZGUARD_FRAMEWORK_HELPERS))->toBe(['now', 'config']);
66: });
67: 
68: it('keeps the kernel free of framework helper calls', function (): void {
69:     expect(SourceScan::callsIn(SourceScan::files('packages/core/src/Kernel'), AZGUARD_FRAMEWORK_HELPERS))->toBe([]);
70: });
71: 
72: arch('exceptions depend only on the kernel')
73:     ->expect('AzGuard\Exceptions')
74:     ->not->toUse(['Illuminate', ...AZGUARD_OUTER_ZONES]);
```

## packages/core/src/Testing/Contracts/PluginContractTests.php

Source: [azguard/packages/core/src/Testing/Contracts/PluginContractTests.php](/home/vostrikov/projects/packages/azguard/packages/core/src/Testing/Contracts/PluginContractTests.php); SHA-256 `49ca1aaf928166bc0e37a5785f66456b095933b2538b65df5a311d83b0606665`.

Lines 14–39:

```text
14: /**
15:  * For the authors of plugins: what every plugin guarantees, checked against the real engine.
16:  *
17:  * Use the trait in a test case that boots a Laravel application and implement `azguardPlugin()`; the plugins yours
18:  * needs next to it go in `azguardCompanions()`.
19:  *
20:  * - the id is stable and the panel lists the plugin;
21:  * - it builds on a clean panel and on two panels, and a panel without it is the panel it was before;
22:  * - `boot()` does not change the panel;
23:  * - a panel that keeps the plugin off is the panel without it, with no trace left.
24:  *
25:  * @api
26:  */
27: trait PluginContractTests
28: {
29:     /** A new instance of the plugin under test. */
30:     abstract protected function azguardPlugin(): Plugin;
31: 
32:     /**
33:      * The plugins the plugin under test requires, attached to the panel next to it.
34:      *
35:      * @return list<Plugin>
36:      */
37:     protected function azguardCompanions(): array
38:     {
39:         return [];
```

Lines 68–103:

```text
68:     #[Test]
69:     public function pluginBuildsOnTwoPanelsAndTouchesOnlyTheOneItIsAttachedTo(): void
70:     {
71:         $alone = ContractWorld::build(null, static fn (PanelBuilder $builder) => null)->get(ContractWorld::OTHER);
72:         $baseline = ContractWorld::snapshot($alone);
73:         $registry = ContractWorld::build(
74:             fn (PanelBuilder $builder) => $builder->plugins([...$this->azguardCompanions(), $this->azguardPlugin()]),
75:             static fn (PanelBuilder $builder) => null,
76:         );
77: 
78:         $this->assertContains($this->azguardPlugin()->id(), $registry->get(ContractWorld::PANEL)->pluginIds(), 'The panel does not list the plugin.');
79:         $this->assertSame($baseline, ContractWorld::snapshot($registry->get(ContractWorld::OTHER)), 'The plugin changed a panel it was not attached to.');
80: 
81:         $both = ContractWorld::build(null, null, fn (PanelBuilder $builder) => $builder->plugins([...$this->azguardCompanions(), $this->azguardPlugin()]));
82:         $this->assertContains($this->azguardPlugin()->id(), $both->get(ContractWorld::PANEL)->pluginIds());
83:         $second = ContractWorld::build(
84:             null,
85:             static fn (PanelBuilder $builder) => null,
86:             fn (PanelBuilder $builder) => $builder->plugins([...$this->azguardCompanions(), $this->azguardPlugin()]),
87:         );
88: 
89:         foreach ([ContractWorld::PANEL, ContractWorld::OTHER] as $id) {
90:             $this->assertContains($this->azguardPlugin()->id(), $second->get($id)->pluginIds(), 'The plugin does not build on panel '.$id.' when it is attached to both.');
91:         }
92:     }
93: 
94:     #[Test]
95:     public function bootDoesNotChangeThePanel(): void
96:     {
97:         $plugin = new ObservedPlugin($this->azguardPlugin(), function (Panel $panel, Closure $boot): void {
98:             $before = ContractWorld::snapshot($panel);
99:             $boot();
100:             $this->assertSame($before, ContractWorld::snapshot($panel), 'boot() changed the panel.');
101:         });
102:         $registry = ContractWorld::build(fn (PanelBuilder $builder) => $builder->plugins([...$this->azguardCompanions(), $plugin]));
103:         $this->assertTrue($registry->isFrozen(), 'boot() unfroze the registry.');
```

## bin/api-manifest.php

Source: [azguard/bin/api-manifest.php](/home/vostrikov/projects/packages/azguard/bin/api-manifest.php); SHA-256 `08c3b30c3317a6f508a1f493330738250010a139f77817c1d71a6a81ab8333c3`.

Lines 5–14:

```text
5: /*
6:  * Machine manifest of the public API of each package (api-manifest.json).
7:  *
8:  *   php bin/api-manifest.php --write   regenerate both manifests
9:  *   php bin/api-manifest.php --check   exit 1 with a short diff when a manifest is stale
10:  *
11:  * Public by location (core): Kernel, Exceptions, Schema, Events, Testing, Storage\Models, Facades\AzGuard,
12:  * Concerns\HasAzGuard, Roles\BaseRole, Panels\{PanelBuilder,Panel,PanelProvider}. Contracts need an @api/@spi tag.
13:  * Any other class is public only with an explicit @api/@spi docblock tag. An @internal class is never published.
14:  */
```
