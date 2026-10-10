# axiomasoft/commerz-testkit

Testbench test case, in-memory fakes of every core port, plugin contract tests and a multi-process concurrency helper.

Part of [Commerz](https://github.com/axiomasoft/commerz), an embeddable commerce
engine for Laravel (PHP 8.4, Laravel 13, PostgreSQL ≥ 16).

## Installation

```bash
composer require --dev axiomasoft/commerz-testkit
```

A development dependency; needs `pcntl` and `posix` for `Concurrency::run()`.

## Documentation

- [Package page](https://github.com/axiomasoft/commerz/blob/main/docs/packages/testkit/index.md)
- [API reference](https://github.com/axiomasoft/commerz/blob/main/docs/reference/api/testkit.md)
- [Architecture](https://github.com/axiomasoft/commerz/blob/main/docs/architecture/README.md)

## License

MIT. See [LICENSE](LICENSE).
