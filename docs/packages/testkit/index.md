# axiomasoft/commerz-testkit

Test tooling for packages and hosts: a Testbench test case, in-memory fakes of
every core port, contract tests for plugins, and a helper that runs code in
parallel processes. It is a **development** dependency.

## Install

```bash
composer require --dev axiomasoft/commerz-testkit
```

Requires the core and `orchestra/testbench` ^11.3.

## What is in it

| Class | Purpose |
|---|---|
| `Commerz\Testkit\PackageTestCase` | Testbench base case: declare the providers, get migrated PostgreSQL, a guard that the database name ends with `_test`, and a re-migration whenever the provider set changes |
| `Commerz\Testkit\Commerce` | `Commerce::fake()` swaps every core port for an in-memory fake and returns them for arrangement and assertions |
| `Commerz\Testkit\Fakes\*` | `FakeStock`, `FakePrices`, `FakePromotionUsage`, `FakeDelivery`, `FakeCustomers`, `FakeSellables`, `FakeSellableRouter` |
| `Commerz\Testkit\PluginContractTests` | A trait of contract tests every plugin should pass |
| `Commerz\Testkit\Concurrency` | `Concurrency::run($workers, $work)` forks processes with their own connections and a start barrier; a worker exception becomes a `RuntimeException` |

Usage and examples: [testing with the testkit](../../how-to/testing-with-testkit).

## Notes

- `Concurrency` needs `pcntl` and `posix`, and a database that is committed (not
  wrapped in a test transaction).
- Fakes make unit tests of host code fast; they do not prove locking. Prove
  concurrency on PostgreSQL.

API reference: [testkit](../../reference/api/testkit).
