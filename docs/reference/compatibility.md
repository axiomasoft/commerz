# Compatibility

Verified on 2026-11-27: PHP 8.6.0 (Ondřej Surý `8.6.0-2`) runs the suite, and the
`postgres:19` image (PostgreSQL 19.0) runs the PostgreSQL suite. The floor stays
PHP 8.4 and PostgreSQL 16. Composer constraints are `>=8.4 <8.7`, so the next
unreleased PHP major is outside the declared range until it is run the same way.

| Component | Supported |
|---|---|
| PHP | 8.4, 8.5 and 8.6 (`>=8.4 <8.7`; extensions `bcmath`, `intl`, `mbstring`, `pdo_pgsql`; `pcntl`/`posix` for the outbox relay's signals and the testkit's concurrency). Continuous integration runs all three. |
| Laravel | ^13 (`illuminate/contracts`, `illuminate/support`, `illuminate/database` ^13) |
| PostgreSQL | ≥ 16; continuous integration runs 16 and 19 |
| `brick/math` | ^0.14.2 \|\| ^0.15 \|\| ^0.16 \|\| ^0.17 |
| Orchestra Testbench (testkit) | ^11.3 |

## Database

PostgreSQL only. Commerz relies on `CHECK`, partial and `UNIQUE … NULLS NOT
DISTINCT` indexes, `EXCLUDE USING gist` (the pricing migration enables
`btree_gist`), `jsonb`, row-level locks (`FOR UPDATE`, `FOR NO KEY UPDATE`,
`FOR KEY SHARE`, `SKIP LOCKED`), advisory locks, sequences and transactional DDL.
SQLite and MySQL are not supported and cannot prove the locking guarantees.

## Packages

All Commerz packages in one installation must be on the same `0.1` line. The
feature packages require the core at `^0.1`; the root repository maps every
package to `0.1.x-dev` through a path repository.

## Tenancy

One connection or database per tenant, chosen by the host through
`commerz.connection`. Several stores and channels in one database are
supported.

## What is deliberately not supported

Model swapping, a shared multi-tenant schema, other databases, currency
conversion, executable configuration and subscriptions stored in the database.
See [Architecture](../architecture/README) for the reasons.
