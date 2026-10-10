# P8.5 review — grok-4.7 (effort high, context 500000)

## Round 1 — session 01a12571-e058-76b1-a256-97220720aa13

Verdict: fail. Six findings, all confirmed by the executor and fixed in the manifest generator (no package code):
1. High: a default naming an unqualified global constant was recorded as a phantom namespaced one
   (`Commerz\Shipping\Rates\Data\PHP_INT_MIN`). Now `\PHP_INT_MIN`; probe test.
2. High: properties lost asymmetric visibility and defaults (`public private(set) array $reserved = []` recorded as
   `public array $reserved`). Now `private(set)` / `protected(set)` (not the implicit one of `readonly`) and the default
   of typed, non-promoted properties; probe test.
3. High: `self` was recovered only for returns; PHP 8.5 reflection resolves `self` in parameters too, so the manifest
   would differ on a supported PHP. Now both, from the AST keyed by `Class::method` (not by start line); probe test.
4. Medium: `final` methods of non-final types were not recorded (`EntityId`, `Ref`). Now `final …`.
5. Medium: `docs/reference/api/sales.md` was stale after the `SetCartCustomer` docblock changed (D38). Regenerated.
6. Low: an interface's parents were stored under `implements`. Now `extends` (list); the renderer follows.
Residual risk accepted: no PHP 8.5 binary locally (the AST path and probes cover the drift); command options are names
only by design; hooks / `new` with arguments / `self|null` unused in the surface.
Checks after the fix: lint, analyse, refactor, Arch (17), api:check (double generation byte-identical),
docs:reference --check. Cost 1.38 USD, 47 turns, 1643 s.

## Round 2 — session 01a1258d-f664-7c70-8580-04014d6fa61d

Verdict: pass, no findings: all six round-1 findings closed on every instance of the eight manifests (8 constant
defaults, 9 asymmetric logs, every `self` parameter/return, the final methods of `EntityId`/`Ref`, `sales.md`,
`Reservations extends StockReservations`); `ApiManifestTest`, `api:check` and `docs:reference --check` green.
Residual risk accepted: no PHP 8.5 binary (AST path covers every public `self`); union order follows reflection;
`implements`/`extends` list ancestors too (only one parented interface today); command options are names only.
Cost 1.22 USD, 45 turns, 1366 s.
