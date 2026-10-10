# P8.3 review — grok-4.7 (effort high, context 500000)

## Round 1 — session 01a12566-4b8e-7293-8907-c7e3a6abdbea

Verdict: pass, two low findings, both confirmed by the executor:
- Low #1: the absent-tables check matched `prefix + noun` only, so `cmz_categories` (plural, as `SchemaOwnership`
  reads it) was invisible. Fixed in the test: singular or `Str::plural` noun.
- Low #2 (with the reviewer's question): no production code read `CustomerProfile::$groups`, although
  docs/architecture (data.md, api.md) say the groups come from `CustomerDirectory`; a CRM passing no groups got
  retail prices. Confirmed as a sales defect (regression red without the fix). Fixed in sales by D38:
  `Carts::setCustomer` stores the directory groups plus the command's (read before the transaction); the CRM test now
  passes no groups and gets `b2b` from the directory.
Residual risks accepted: free-delivery threshold vs service lines not separated (both carts below 5000);
`paymentDueAt` of a draft not asserted (null by placement for drafts).
Checks after the fix: lint, analyse, refactor, Sales suite (467), EndToEnd (22), api:check — green.
Cost 2.18 USD, 80 turns, 1487 s.

## Round 2 — session 01a12581-4734-7861-9a58-f2af2e8f246d

Verdict: pass, no findings: both round-1 findings closed (directory groups first, then the command's, deduplicated,
read before the transaction; quote and place use the cart snapshot; idempotent repeat; plural nouns match every
absent package's table and none of the installed ones). Residual risk taken by the executor: the `withCustomer`
comment in `docs/architecture/api.md` now says explicit groups, and that `setCustomer` adds the directory's (D38).
Accepted: groups are a cart snapshot until the next `setCustomer` (email, name and tax exemption are read live).
Cost 0.78 USD, 36 turns, 824 s.
