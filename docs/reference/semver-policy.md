# SemVer policy

Commerz follows [Semantic Versioning](https://semver.org). This page says what
is covered.

## The public surface

| Surface | Covered |
|---|---|
| Types marked `#[Api]` outside `Internal` | class, interface and enum names; public method signatures, constructor parameters (including names, because commands are built with named arguments), enum case names and backing values |
| Types marked `#[Spi]` | the contract plugins and hosts implement or consume; the `kind` constant and its contribution key |
| Command, query and view shapes | public properties and their types |
| Error codes and categories | `code()` strings, `ErrorCategory` values |
| Outbox events | `type` names, payload fields of a `version` |
| Contribution kinds and built-in keys | `sellable_provider`, `quote_stage`, … and keys like `flat`, `order.percent_off` |
| Plugin ids | `commerz.core`, `commerz.catalog`, … |
| Artisan commands | names, options and exit codes |
| Configuration | file names, keys, accepted values |
| Database schema | migrations, table and column names, constraint names, `cmz_` prefix rules |
| Composer packages | names, `require` constraints, provider discovery |

**Not covered:** anything under an `Internal` namespace or `Internal` directory,
private and protected members of non-SPI classes, the text of exception
messages, the contents of `meta` and `explanation` strings, the order of rows
where no ordering is documented, and test code.

The surface is enforced: architecture tests fail when a public type outside
`Internal` has no `#[Api]` or `#[Spi]`, when a package imports another's
`Internal`, and when the generated manifest (`php-api.json`) drifts
(`composer api:check`). The manifests render into the
[API reference](./api/).

## Rules by release type

| Release | May contain |
|---|---|
| patch | bug fixes that keep documented behavior; no schema change that existing code can observe |
| minor | additive API (new types, new optional parameters at the end, new enum cases where documented as extensible), new migrations that only add, new kinds and keys, new error codes, deprecations |
| major | removals, renames, signature changes, changed codes, destructive migrations |

Removing a feature takes a deprecation in a minor release first.

## Extending enums and events

A new enum case or a new outbox `type` is not a breaking change: write
`match` statements with a default arm and consumers that ignore types they do
not handle. A new payload field of an event is additive; removing or changing
one raises the event's `version`.

## Pre-1.0

Until 1.0 the policy is a promise of intent: minor releases (`0.x`) may change
the marked surface, with the change called out in the release notes. Packages
are versioned together and require each other at `^0.1`.
