I'll review P3.1–P3.4 against the inventory design and decisions, starting with the contract documents, the committed diff, and the uncommitted stock-ledger work.The committed inventory surface is large. I'll read the design contract and the P3 item records first, then the stock-ledger and allocation code against them.P3.4 restock can commit the wrong quantity and the wrong location. The ledger itself holds for the paths the tests cover: 126 inventory tests passed, and the 8-process ledger concurrency test passed (81 movements, sequential `balance_after`, `on_hand` 100).

Archive versus receive is ordered so the inserter holds the item and the location `FOR SHARE` before `ensureLevels`, and archive takes the entity `FOR NO KEY UPDATE` first. Count compares `expectedOnHand` to the locked row. Idempotent stock commands and restock share one transaction with the receipt, so a crash before commit does not leave a movement without a receipt. The P3.2 backorder deviation matches its unit tests. I did not re-run the core port suite.

The link and source races are real on PostgreSQL. While one transaction held the item and the location `FOR SHARE`, a second transaction updated `quantity_per_unit` from 2 to 9 and deleted the channel source in 3.6 ms. A re-read in the first transaction saw `qpu = 9` and no source. `DatabaseStockReturns` never re-reads either.

```text
t1_read_qpu_before_lock=2
t2_update_and_unassign_while_t1_holds_share_ms=3.6 rows=1
t1_reread_qpu_after_t2_commit=9
t1_sources_left=0
```

```json
{
  "verdict": "fail",
  "findings": [
    {
      "id": "F1",
      "severity": "high",
      "item": "P3.4",
      "file": "packages/inventory/src/Stock/Internal/DatabaseStockReturns.php",
      "line": 130,
      "summary": "Restock applies quantity_per_unit from an unlocked link read, and link or unlink never takes a lock that conflicts with the item FOR SHARE, so the committed delta can disagree with the links that exist at commit.",
      "failure_scenario": "Sellable S is linked to item A with quantity_per_unit 2. T1 restock(S, qty 1) reads qpu 2. T2 sets qpu to 9, or unlinks S, and commits. T1 locks A FOR SHARE (no wait) and moves +2. on_hand rises by 2 while the committed link is 9, or stock is created for a sellable that now has no link (StockLinkMissing was required).",
      "reproduction": "componentUnits() selects stock_item_links with no FOR SHARE/UPDATE (DatabaseStockReturns.php:130-134) and apply() moves those units (lines 109-113). items() only re-checks policy and archived status (lines 83-91). link() locks the item FOR SHARE (DatabaseStockItems.php:181); unlink() deletes the link with no item lock (lines 215-222). FOR SHARE does not conflict with FOR SHARE, and neither statement locks the link row. Live check while T1 held the item and location FOR SHARE: T2's update committed in 3.6ms and T1's re-read saw qpu 9. A test that pauses inside apply() after componentUnits() and unlinks before move() fails today.",
      "confidence": "confirmed"
    },
    {
      "id": "F2",
      "severity": "medium",
      "item": "P3.4",
      "file": "packages/inventory/src/Stock/Internal/DatabaseStockReturns.php",
      "line": 176,
      "summary": "Restock with no locationCode chooses the first channel source without locking that source row, and unassign does not lock the location, so the units can land on a location that is no longer a source.",
      "failure_scenario": "Channel sources are warehouse (priority 0) and store (priority 10), both active and empty. T1 restock reads warehouse as the first source. T2 unassigns warehouse and commits. T1 locks warehouse FOR SHARE, sees it still active, and receives the return there. The channel now fulfills only from store, which stays at 0.",
      "reproduction": "location() reads stock_sources with no row lock (DatabaseStockReturns.php:176-182), then locks only the location (StockEntities::location). unassign() deletes the source row and locks nothing (DatabaseStockSources.php:72-78). The same probe as F1 deleted the source while T1 held the location FOR SHARE (sources_left=0, 3.6ms, statement_timeout 3s did not fire).",
      "confidence": "confirmed"
    },
    {
      "id": "F3",
      "severity": "medium",
      "item": "P3.3",
      "file": "packages/inventory/src/Items/Internal/DatabaseStockItems.php",
      "line": 94,
      "summary": "Switching a backorder item to tracked does not look at reserved versus on_hand and safety stock, so a level can violate INV-02 with no StockShortfall.",
      "failure_scenario": "Item policy is backorder, on_hand is 1, safety_stock is 0. StockLedger::hold(5, backorder: true) sets reserved to 5. UpdateStockItem changes policy to tracked. The item is tracked with reserved 5 > on_hand 1 and no StockShortfall was dispatched.",
      "reproduction": "update() rejects only the untracked transition when a level is non-empty (DatabaseStockItems.php:94-100). hold() allows the reservation when $backorder is true (StockLedger.php:195-203). A feature test that receive()s 1, holds 5 through the ledger the way StockLevelsTest::lvlHold does, then updates the policy to Tracked, observes policy tracked, reserved 5, on_hand 1, and zero StockShortfall events.",
      "confidence": "confirmed"
    },
    {
      "id": "F4",
      "severity": "medium",
      "item": "P3.2",
      "file": "packages/inventory/src/Allocation/Internal/AllocationPlanValidator.php",
      "line": 61,
      "summary": "The plan validator skips backorder components before the int64 multiply, so a plan whose units times quantity_per_unit overflows is accepted.",
      "failure_scenario": "A need asks for PHP_INT_MAX units of one backorder component with quantity_per_unit PHP_INT_MAX. PriorityAllocation places all of it (pass 2 treats backorder as unlimited). AllocationPlanValidator::validate returns without throwing. Applying the plan needs PHP_INT_MAX * PHP_INT_MAX stock units.",
      "reproduction": "foreach components { if ($component->backorder) continue; } at AllocationPlanValidator.php:60-63 runs before Quantities::mul. Tracked overflow is tested (AllocationPlanValidatorTest 'int64 overflow of stock units'); the backorder twin is not. Expect validate() to throw inventory.invalid_allocation_plan for planned units PHP_INT_MAX and qpu PHP_INT_MAX; it currently returns.",
      "confidence": "confirmed"
    },
    {
      "id": "F5",
      "severity": "low",
      "item": "P3.4",
      "file": "packages/inventory/tests/Feature/Stock/LedgerConcurrencyTest.php",
      "line": 28,
      "summary": "Nothing concurrent proves the archive-versus-ensureLevels lock order, so a regression that inserts a level after archive has passed its emptiness check would stay green.",
      "failure_scenario": "T1 archive locks the location, sees no levels, and has not yet set status. T2 receive inserts a level and commits. T1 archives the location. The level now has on_hand > 0 on an archived location. The current lock order is meant to make T2 wait on FOR SHARE until T1 commits, then refuse the increase; no test fails if that order is reversed.",
      "reproduction": "LedgerConcurrencyTest only mixes receive(+1) and adjust(-1) on one level. StockLevelsTest archives only after stock is already zero, in a single process. There is no Concurrency::run pairing archive(location|item) with receive/restock.",
      "confidence": "confirmed"
    }
  ]
}
```