# P8.7 review — round 1 (grok/grok-4.7, session 01a12704-37c0-7f00-a837-c95d4e16aaa7)

Route: grok-4.7, effort high (launched through the orch bridge `launch reviewer grok --model grok-4.7 --effort high`).
Raw verdict: fail — 1 Medium, 1 Low. Answer: `grok-answer.md`.

| # | Severity | Executor check | Outcome |
|---|---|---|---|
| 1 | Medium: archive check and doctor do not prove feature-package migrations ship and run | Confirmed by reading: `assert_archives` only required composer.json/LICENSE; doctor fingerprints core only; migrate exits 0 on a missing directory | Fixed: `assert_archives` requires every `database/migrations`, `config` and `src` php file of the source package in the zip; `smoke.php` requires migrations in each installed package and each recorded in the `migrations` table |
| 2 | Low: `illuminate/console` not required though commands extend `Illuminate\Console\Command` | Confirmed: grep shows Console used in core, inventory, promotions, sales; none required it (host `laravel/framework` hid it) | Fixed: `"illuminate/console": "^13.0"` in the four packages; root lock refreshed with `composer update axiomasoft/* --no-scripts` |

Residual risks accepted from the reviewer: testkit is archived but never installed; the fixed consumer DB name does not tolerate two overlapping runs.
Checks after the correction: V1–V5, V7, V8 exit 0 (2106 tests).
