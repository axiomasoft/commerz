# Read-only review: item P8.5 of PLAN1 (API manifest and generated API reference)

You are an independent reviewer. Inspect, report findings, stop. Do NOT modify the repository: the kernel refuses
writes outside `vendor/`. KEEP IT FOCUSED: no full suites, no phpstan/pint/rector runs, no database. You may run, one at
a time: `scripts/php84 vendor/bin/pest --do-not-cache-result tests/Arch/ApiManifestTest.php`,
`scripts/php84 php scripts/generate-api-reference.php --check`, `scripts/php84 php scripts/generate-api-manifest.php --check`
(never without `--check`: that writes files). Other sessions work in the same checkout. A reproduction you cannot write to
disk may be given as a PHP/Pest snippet plus the reasoning. Stop after ~20 minutes of work.

Repository: `/home/vostrikov/projects/packages/commerz` — Laravel 13 / PHP 8.4 package monorepo.

## Scope (UNCOMMITTED)

- New: `tests/Arch/Support/{ApiSurface,ApiManifest}.php` (namespace `Commerz\Arch`, built on the existing
  `SourceScanner`), `tests/Arch/ApiManifestTest.php`, `scripts/generate-api-manifest.php` (double generation +
  hash check), `scripts/generate-api-reference.php` (`[--check]`), generated
  `packages/{core,catalog,inventory,pricing,promotions,shipping,sales,testkit}/resources/contracts/php-api.json` and
  `docs/reference/api/{index,core,…,testkit}.md`.
- Changed: root `composer.json` (scripts `api:manifest`, `api:check`, `docs:reference`), `.github/workflows/ci.yml`
  (lint job runs `composer api:check` and `composer docs:reference -- --check`).

Ignore every other uncommitted change (`plans/`, `tests/EndToEnd/**`): not in this review.

## Contract

`plans/2026.10.08-№1-COMMERZ-FOUNDATION/knowledge/assembly-design.json` section `api-manifest` (format, model,
determinism, mechanics, reference); item record `phases/P8/P8.5.json` (scope, rules, validation V1–V7); decisions D3,
D12, D37; logged P8.5 note in `plans/…/log.jsonl` (`"item":"P8.5"`). External sample (read-only, optional):
`/home/vostrikov/projects/packages/chatom/tests/Arch/{ApiManifestTest.php,Support/ApiManifest.php}`.

## What to look for (most important first)

1. Completeness and correctness of the surface: is every public (`#[Api]`/`#[Spi]`, non-`Internal`) type of each package
   present and nothing internal leaked; members (public + protected of extensible types; methods inherited from a
   non-public parent); signatures (types incl. nullable/union/intersection/DNF, defaults incl. constants/enums/`new`,
   variadic/by-ref, `self`/`static`), enum cases, constants, properties. Find a member whose change would NOT change the
   manifest (a SemVer hole), or a manifest line that misrepresents the code.
2. Determinism across environments: ordering (reflection order, glob order, ksort on every level), PHP 8.4 vs 8.5
   differences, locale/filesystem dependence, absolute paths leaking into output.
3. Platform section: providers, config, commands, plugin, tables, kinds — correct for each package?
4. Reference generator: correctness of the markdown for VitePress (anchors, escaping of `<`, `{{`, `|` in tables and
   prose, code fences), stale/orphan detection in `--check`, the `index.md`.
5. Wrapper/CI wiring: `--check` semantics, exit codes, `PHP_BINARY` use under `scripts/php84`, the composer scripts.
6. Anything outside the item's scope.

## Output

For every finding: severity (high/medium/low), file:line, the defect in one sentence, and a REPRODUCTION (failing
test/snippet or command output). A finding without a reproduction is at most "plausible". Questions separately.
Finish with `Verdict: pass` or `Verdict: fail` and one paragraph of residual risk.
