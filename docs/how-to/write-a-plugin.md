# Write a plugin

A plugin adds behavior under a stable key. Everything extensible in Commerz is a
**contribution** of a **kind** — see the closed list in
[extension kinds](../reference/extension-kinds).

## 1. The plugin class

```php
use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\PluginManifest;
use Commerz\Plugins\PluginRegistrar;

final class AcmePlugin implements CommerzPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'acme.loyalty',               // vendor.name, [a-z0-9.-]
            version: '1.0.0',
            requires: ['commerz.promotions'], // plugins that must be loaded first
        );
    }

    public function register(PluginRegistrar $registrar): void
    {
        // declarations only: no database, no network
        $registrar->contribute(PromotionEffect::KIND, LoyaltyDoubler::class);
    }
}
```

A contribution is an object or a class name resolved through the container when
the registry compiles. Its key comes from its own `key()`.

## 2. The contribution

Each kind has a contract. A promotion effect, for example, is pure — it reads
the input and returns discounts, with no writes and no network:

```php
final class LoyaltyDoubler implements PromotionEffect
{
    public function key(): string { return 'acme.loyalty_x2'; }   // vendor-prefixed
    public function version(): int { return 1; }
    public function stages(): array { return [PromotionStage::Cart]; }
    // normalize(), upgrade() and apply(EffectInput): list<Discount> follow
}
```

Keys of host contributions are prefixed with the vendor (`acme.`), so they never
collide with built-ins.

## 3. Register it

```php
// config/commerz.php
'plugins' => [App\Commerce\AcmePlugin::class],
```

The registry is compiled lazily on first use and frozen for the process. An
unknown kind, a duplicate key, a wrong interface, an unknown required plugin or
a cycle fails with `InvalidConfiguration` at boot.

```bash
php artisan packman:plugins        # the compiled plugins, in load order
php artisan packman:doctor
```

## Rules to keep

- `register()` only declares. All I/O lives in the contribution.
- A rule contribution (condition, effect, rate) decodes and upgrades its own
  stored configuration (`version()`, `normalize()`, `upgrade()`); data stores the
  **key** and the **config version**, never a class name.
- If your plugin is removed, rules that used it become `unsupported` and fail
  closed. Never rely on a plugin being present for old data to stay valid.
- Depend on a package plugin by listing it in `requires`
  (`commerz.promotions`, `commerz.catalog`, …).
- Contributions are shared, stateless instances: no request state.

## 4. Test it

`PluginContractTests` (testkit) compiles your plugin the way the application
does and checks the contract:

```php
final class AcmePluginTest extends PackageTestCase
{
    use PluginContractTests;

    protected function pluginClass(): string { return AcmePlugin::class; }
    protected function commerzProviders(): array { return [CommerzServiceProvider::class, PromotionsServiceProvider::class]; }
}
```

See [testing with the testkit](./testing-with-testkit).
