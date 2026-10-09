Verdict: pass

No findings. The uncommitted P5.7 code holds PRM-01 under the D27 protocol, and the recorded deviations match the log.

What was checked:

- Lock order is the redemption header, then coupons, then promotions, then customer usages, each by id ascending. `EXPLAIN` shows `LockRows` above `Sort`, so `ORDER BY id FOR KEY SHARE` locks in that order. Unlimited rows are key-shared and not updated. Limits are conditional `UPDATE`s or `INSERT … ON CONFLICT DO UPDATE … WHERE used < limit`. A two-session probe (key share, then a waiting `FOR UPDATE`, then the counter `UPDATE`) completed the update without `40P01`.
- The same payload is a no-op, including list order. A different payload raises `IdempotencyConflict` (`promotions.consume`). Restore follows `restore_on`, is a no-op the second time and for an unknown order, and uses `greatest(…, 0)`. Definition changes take `FOR UPDATE` and `UsageCounters::recompute`, which cannot pass a consume that already holds `KEY SHARE`.
- Both methods run in `Transactions::run`. The limit tests run inside `RefreshDatabase` and still see the failed order fully rolled back, so a nested savepoint does not poison the caller.
- `commerz:promotions:reconcile` is read-only, uses one repeatable-read snapshot when it opens the transaction, and reports counter drift plus unsupported rules and activators. Archived promotions are left out of the rule report on purpose (the console test locks that in).
- `PromotionUnavailable` is public `#[Api(since: '0.1')]`, category conflict, code `promotions.promotion_unavailable`, fields `promotionId`, `couponCode`, `reason`. The provider binds `PromotionUsage` to `DatabasePromotionUsage`.

`vendor/bin/pest` on `packages/promotions/tests/Feature/Usage` and `…/Console`: 41 passed. PHPStan on the new usage, command, and provider sources: no errors.
