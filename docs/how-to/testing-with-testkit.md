# Test with the testkit

```bash
composer require --dev axiomasoft/commerz-testkit
```

## Which test to write

| You want to test | Use |
|---|---|
| Host code that calls Commerz ports | `Commerce::fake()` — in-memory, fast, no database |
| A whole flow on real packages | `PackageTestCase` with the real providers on PostgreSQL |
| Locking and idempotency | `Concurrency::run()` on committed data |
| A plugin | `PluginContractTests` |

## Fakes

```php
use Commerz\Testkit\Commerce;

$commerce = Commerce::fake();                       // every core port swapped
$commerce->stock->limit($ref, 3);                   // arrange
$commerce->sellables->add($snapshot);

// … run the code under test …
```

`Commerce::fake()` replaces the stock, price, promotion-usage, delivery,
customer and sellable ports with the in-memory fakes of `Commerz\Testkit\Fakes`,
and the quote stages and the `Commerz` root see them even when they were resolved
before. Assert on what the fakes recorded. Fakes make host tests fast; they do
not prove locking.

## Real packages on PostgreSQL

```php
use Commerz\Testkit\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, PricingServiceProvider::class, SalesServiceProvider::class];
    }
}
```

`PackageTestCase` is an Orchestra Testbench case. It refuses to migrate unless
the database name ends in `_test`, and migrates again when the provider set
changes between tests, so suites of several packages can share a process. Point
it at PostgreSQL: `DB_HOST`, `DB_PORT`, `DB_DATABASE` (`commerz_test`),
`DB_USERNAME`, `DB_PASSWORD`. The repository's `docker-compose.yml` starts one.

Configure a subset the way a host would, in `defineEnvironment`:

```php
protected function defineEnvironment($app): void
{
    parent::defineEnvironment($app);
    $app['config']->set('commerz.stock', 'unlimited');
    $app['config']->set('commerz.plugins', [AcmePlugin::class]);
}
```

Gateways, carriers, the outbox relay and refunds refuse to run inside a
transaction, so tests that exercise them cannot use `RefreshDatabase`: commit
the data and truncate afterwards.

## Concurrency

```php
use Commerz\Testkit\Concurrency;

$results = Concurrency::run(8, fn (int $worker) => $reservations->reserve($command));
```

It forks N processes with their own connections, starts them behind a barrier,
and returns their results in worker order; a worker that throws or crashes
raises a `RuntimeException`. The data must be **committed**: the parent's
connections are closed before forking. It needs `pcntl` and `posix`.

Assert the invariant, not the timing: N parallel reservations of the last unit
leave exactly one set of lines.

## Plugin contract tests

```php
final class AcmePluginTest extends PackageTestCase
{
    use PluginContractTests;

    protected function pluginClass(): string { return AcmePlugin::class; }
    protected function commerzProviders(): array { return [CommerzServiceProvider::class, PromotionsServiceProvider::class]; }

    // Pin the keys you contribute: a renamed key silently orphans stored rules.
    protected function expectedContributions(): array
    {
        return ['promotion_effect' => ['acme.loyalty_x2']];
    }
}
```

The plugin is compiled together with `commerz.core` and its dependencies the way
the application does it. If it requires plugin classes that the providers do not
register, return them from `pluginDependencies()`. The tests check that
contribution keys are stable and unique, that registering the plugin twice
fails, that an unregistered plugin leaves no contributions, and that an unknown
key is unsupported.
