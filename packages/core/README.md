# axiomasoft/commerz

Embeddable commerce domain core: money, commerce context, ports, plugins, stores and channels, idempotent operations and the transactional outbox.

Part of [Commerz](https://github.com/axiomasoft/commerz), an embeddable commerce
engine for Laravel (PHP 8.4, Laravel 13, PostgreSQL ≥ 16).

## Installation

```bash
composer require axiomasoft/commerz
php artisan commerz:install
php artisan commerz:doctor
```

Every other Commerz package builds on it; it works alone with default ports (unlimited stock, base prices, no promotions, no delivery).

## Documentation

- [Package page](https://github.com/axiomasoft/commerz/blob/main/docs/packages/core/index.md)
- [API reference](https://github.com/axiomasoft/commerz/blob/main/docs/reference/api/core.md)
- [Architecture](https://github.com/axiomasoft/commerz/blob/main/docs/architecture/README.md)

## License

MIT. See [LICENSE](LICENSE).
