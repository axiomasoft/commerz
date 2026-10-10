# Read-only re-review (round 2, the last): item P8.5 of PLAN1 — the owning correction of round 1

You are an independent reviewer. Inspect, report findings, stop. Do NOT modify the repository (the kernel refuses
writes outside `vendor/`). No database, no full suites, no phpstan/pint/rector. You may run, one at a time:
`scripts/php84 vendor/bin/pest --do-not-cache-result tests/Arch/ApiManifestTest.php`,
`scripts/php84 php scripts/generate-api-manifest.php --check`, `scripts/php84 php scripts/generate-api-reference.php --check`
(never without `--check`). If `scripts/php84` cannot refresh its shim, use `/usr/bin/php8.4` directly. Stop after ~15 minutes.

Repository: `/home/vostrikov/projects/packages/commerz`. The item's code and the correction are commit `5965575`
(`git show 5965575`). Your round-1 findings and the executor's verdict:
`plans/2026.10.08-№1-COMMERZ-FOUNDATION/phases/P8/P8.5/review/{grok-answer.md,verdict.md}`.

## The correction (tests/Arch/Support/{ApiManifest,ApiSurface}.php, scripts/generate-api-reference.php, regenerated files)

1. Defaults naming constants: class constants as reflected; a global constant as `\NAME` (also when reflection
   namespaces it); probe test.
2. Properties: `private(set)` / `protected(set)` (not the implicit one of `readonly`), the default of typed, non-promoted
   properties; probe test.
3. `self` in parameters and returns recovered from the AST, keyed by `Class::method` (NameResolver), so PHP 8.5's
   resolution of `self` does not change the manifest; probe test.
4. `final` on methods of non-final types.
5. `docs/reference/api/sales.md` regenerated.
6. Interfaces record their parents under `extends` (list); the renderer prints them.

## What to look for

- Is each round-1 finding closed on every instance in the 8 manifests (not only the probes)? Any new misstatement the
  correction introduced (e.g. `self` replacement touching another class name, constants inside arrays or `new`, enum
  case defaults, inherited members from Internal parents, trait/enum edge cases, readonly classes and `protected(set)`).
- Determinism and PHP-version independence after the change; `--check` behaviour.
- Anything else the correction broke.

## Output

For every finding: severity, file:line, the defect in one sentence, and a REPRODUCTION. A finding without one is at
most "plausible". Finish with `Verdict: pass` or `Verdict: fail` and one paragraph of residual risk.
