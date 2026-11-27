# Commerz

An embeddable, headless commerce engine for Laravel. Commerz owns the domain of
trade — stores and channels, catalog, stock, prices and taxes, promotions and
coupons, delivery, and cart → order → payment → fulfillment → return — as a
family of Composer packages. It is transport-agnostic: the host application
builds its storefront, API or CRM on top and keeps its own data.

- **Embeddable.** A shop installs everything; a CRM installs the core and sales
  and sells its own entities through a `SellableProvider`.
- **Typed.** Commands are `final readonly` data classes, modules are contracts,
  public types carry `#[Api]` / `#[Spi]` and follow SemVer.
- **Correct under load.** Invariants live in PostgreSQL (constraints, locks, a
  fixed lock order) and are proved by N-process concurrency tests.
- **Extensible.** Five extension mechanisms and a closed set of plugin kinds:
  payment gateways, carriers, tax calculators, promotion rules, quote stages.

Stack: PHP 8.4–8.6, Laravel 13, PostgreSQL ≥ 16 (CI runs 16 and 19).

## Packages

| Package | Composer | |
|---|---|---|
| [core](packages/core) | `axiomasoft/commerz` | money, context, ports, plugins, stores and channels, operations, outbox |
| [catalog](packages/catalog) | `axiomasoft/commerz-catalog` | products, variants, attributes, categories, listings |
| [inventory](packages/inventory) | `axiomasoft/commerz-inventory` | locations, stock levels, reservations, transfers |
| [pricing](packages/pricing) | `axiomasoft/commerz-pricing` | price lists, prices, taxes |
| [promotions](packages/promotions) | `axiomasoft/commerz-promotions` | promotions, coupons, usage limits |
| [shipping](packages/shipping) | `axiomasoft/commerz-shipping` | zones, methods, rates, carrier bookings |
| [sales](packages/sales) | `axiomasoft/commerz-sales` | carts, checkout, orders, payments, fulfillment, returns, refunds |
| [testkit](packages/testkit) | `axiomasoft/commerz-testkit` | test case, port fakes, concurrency helper |
| [full](packages/full) | `axiomasoft/commerz-full` | metapackage: the core and all feature packages |

## Quick start

```bash
composer require axiomasoft/commerz-full
php artisan commerz:install
php artisan packman:doctor
```

```php
use Commerz\Context\CommerceContext;
use Commerz\Money\Currency;
use Commerz\Sales\Carts\Data\AddCartLine;
use Commerz\Sales\Carts\Data\CreateCart;
use Commerz\Sales\Checkout\Data\PlaceOrder;
use Commerz\Sales\Facades\Sales;

$context = CommerceContext::for($channelId, Currency::of('RUB'), 'ru');
$cart = Sales::carts()->create(new CreateCart($context))->id;
Sales::carts()->addLine(new AddCartLine($cart, $variantSellable, 2));

$quote = Sales::carts()->quote($cart);                       // what the buyer sees
$order = Sales::checkout()->place(new PlaceOrder($cart, $quote->total)); // what the buyer accepted
```

Stores, channels, catalog, stock, prices and delivery are data you create
through the same typed API; the documentation walks through a whole shop.

## Documentation

The documentation is a VitePress site in [`docs/`](docs):

```bash
npm ci --prefix docs
npm run docs:dev --prefix docs
```

- Getting started: [introduction](docs/getting-started/introduction.md),
  [installation](docs/getting-started/installation.md),
  [quick start: shop](docs/getting-started/quick-start-shop.md),
  [embedding in a CRM](docs/getting-started/crm-embedding.md)
- [Architecture](docs/architecture/README.md) — the binding design documents
- [API reference](docs/reference/api/index.md) — generated from the package manifests

## Development

Packages live under `packages/<name>/`. Check out Packman at tag `v0.1.1` in
`../packman` for the Composer path repositories. Run PHP and Composer with
`bin/php84`.

```bash
bin/php84 composer lint:check
bin/php84 composer analyse
bin/php84 composer refactor:check
bin/php84 composer test
bin/php84 composer test:pg       # PostgreSQL integration and concurrency tests
```

## License

MIT. See [LICENSE](LICENSE).
