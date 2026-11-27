# axiomasoft/commerz-pricing

Price lists, prices with validity windows, and taxes; implements `PriceResolver` and the `tax` quote stage.

Part of [Commerz](https://github.com/axiomasoft/commerz), an embeddable commerce
engine for Laravel (PHP 8.4–8.6, Laravel 13, PostgreSQL ≥ 16).

## Installation

```bash
composer require axiomasoft/commerz-pricing
```

Config: `commerz-pricing.php`. Plugin `commerz.pricing`, kind `tax_calculator`. Needs PostgreSQL with the `btree_gist` extension.

## Documentation

- [Package page](https://github.com/axiomasoft/commerz/blob/main/docs/packages/pricing/index.md)
- [API reference](https://github.com/axiomasoft/commerz/blob/main/docs/reference/api/pricing.md)
- [Architecture](https://github.com/axiomasoft/commerz/blob/main/docs/architecture/README.md)

## License

MIT. See [LICENSE](LICENSE).
