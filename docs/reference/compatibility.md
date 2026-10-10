# Compatibility

| Component | Supported |
|---|---|
| PHP | ^8.4 (extensions `bcmath`, `intl`, `mbstring`, `pdo_pgsql`; `pcntl`/`posix` for the outbox relay's signals and the testkit's concurrency) |
| Laravel | ^13 (`illuminate/contracts`, `illuminate/support`, `illuminate/database` ^13) |
| PostgreSQL | ≥ 16; continuous integration runs 17 |
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
