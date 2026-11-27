# axiomasoft/commerz-inventory

Stock locations, items and levels, reservations and transfers; implements the core stock ports.

Part of [Commerz](https://github.com/axiomasoft/commerz), an embeddable commerce
engine for Laravel (PHP 8.4–8.6, Laravel 13, PostgreSQL ≥ 16).

## Installation

```bash
composer require axiomasoft/commerz-inventory
```

Config: `commerz-inventory.php`. Plugin `commerz.inventory`, kind `allocation_strategy`. Commands `commerz:inventory:reconcile` and `commerz:inventory:expire-reservations`.

## Documentation

- [Package page](https://github.com/axiomasoft/commerz/blob/main/docs/packages/inventory/index.md)
- [API reference](https://github.com/axiomasoft/commerz/blob/main/docs/reference/api/inventory.md)
- [Architecture](https://github.com/axiomasoft/commerz/blob/main/docs/architecture/README.md)

## License

MIT. See [LICENSE](LICENSE).
