# Read-only re-review (round 2): item P8.7 of PLAN1 — correction of round 1

You are an independent reviewer. Inspect, report findings, stop. Do NOT modify the repository (the kernel refuses
writes outside `vendor/`). No phpstan/pint/rector/full suites; `scripts/test-consumer-install.sh` writes under `var/`
so you cannot run it. Repository `/home/vostrikov/projects/packages/commerz`; the correction is commit `0555c1d`
(`git show 0555c1d`; earlier round: `plans/2026.10.08-№1-COMMERZ-FOUNDATION/phases/P8/P8.7/review/{grok-answer.md,verdict.md}`).
Stop after ~15 minutes.

Round-1 findings and the executor's corrections:
1. Medium — migrations of feature packages unproven. Fix: `assert_archives` (script) now requires every
   `database/migrations`, `config` and `src` top-level `*.php` of the source package in its zip (package name taken from
   the archive file name); `tests/Consumer/smoke.php` requires every installed package to ship migrations and every
   migration file to be recorded in the `migrations` table.
2. Low — `illuminate/console` missing. Fix: `"illuminate/console": "^13.0"` in core, inventory, promotions, sales
   `composer.json`; root lock refreshed. Executor re-ran: lint, phpstan, rector, api:check, 2106 tests, consumer
   smoke (both profiles `smoke: ok`), agent docs — all exit 0.

Check only the correction and its closure: does the new archive check really bite (file-name → package mapping for
`core`, `full`, `testkit`; paths of the zip entries; packages without `database/`)? Could the smoke migration check
pass vacuously (`migrations` table name, `glob` result, `array_flip` keys, packages whose migrations live elsewhere)?
Is any other package using an `illuminate/*` component it does not require (same gap class as finding 2: grep
`use Illuminate\` per package against `require`)? Anything the correction broke.

Output: per finding severity, file:line, one sentence, REPRODUCTION (command output). Without one it is at most
"plausible". End with `Verdict: pass` or `Verdict: fail` and one paragraph of residual risk.
