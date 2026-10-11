P3.5’s lock order holds on READ COMMITTED: reserves take items and source locations `FOR SHARE`, then one ordered level lock, and foreign headers only with `SKIP LOCKED`. Tracked stock cannot be oversold. Two reservation flows still break the port.

Untracked and unlimited sellables are accepted by `reserve` and then rejected by `consume`. An active reservation that stored no lines is also rewritten on a repeat, so the retry is not idempotent.

Concurrency against archive, receive, consume, release, and the sweeper stays in the documented order (header, then levels, then lines). `StockLedger::hold` and `ship` are the backstop for INV-02 and INV-04. The existing concurrency tests cover the last unit, shared kits, reverse line order, expire versus consume, and one demand reserved four times. They never cover a line-less reservation.

```json
{
  "verdict": "fail",
  "findings": [
    {
      "id": "F1",
      "severity": "high",
      "file": "packages/inventory/src/Reservations/Internal/DatabaseReservations.php",
      "line": 142,
      "summary": "consume rejects an untracked or unlimited sellable that reserve just accepted, because that sellable has no lines so outstanding is 0.",
      "failure_scenario": "Channel web, source main, item ebook policy untracked linked to variant:ebook. reserve(order:1, ebook qty 2) returns ReservedLine quantity 2 and writes no lines. consume(order:1, ebook, 2, fulfillment:1) throws ReservationNotFound. The same happens for unlinked_sellables=unlimited. UnlimitedStock::consume and FakeStock::consume both succeed after that reserve.",
      "reproduction": "it('consumes an untracked sellable', function (): void {\n    $channel = rsvChannel();\n    rsvSource($channel, 'main');\n    rsvItem('ebook', ['ebook' => 1], StockPolicy::Untracked);\n    rsvReserve($channel, 'order:1', ['ebook' => 2]);\n    Inventory::reservations()->consume(new ConsumeReservation('order:1', SellableRef::of('variant', 'ebook'), 2, 'fulfillment:1'));\n    expect(Inventory::reservations()->find('order:1')->state)->toBe(ReservationState::Closed);\n});\nCode path: lines() is empty, available stays 0, quantity 2 fails the check at DatabaseReservations.php:142. No database run; the existing test 'holds nothing for untracked sellables' already shows zero lines.",
      "confidence": "confirmed"
    },
    {
      "id": "F2",
      "severity": "medium",
      "file": "packages/inventory/src/Reservations/Internal/DatabaseReservations.php",
      "line": 244,
      "summary": "A repeat of an active reservation that has no lines is treated as a new generation: the header is rewritten and StockReserved fires again.",
      "failure_scenario": "reserve(order:1, ebook untracked, expiresAt +1 hour) stores an active header and no lines. The same reserve again, or the same demand with expiresAt null, does not take the repeat branch (no consumed units and no outstanding lines). reactivate() sets generation to 2 and replaces expires_at, then StockReserved is dispatched. A retry that also names a tracked tee with stock on hand reserves the tee.",
      "reproduction": "it('repeats an untracked demand without writing', function (): void {\n    $channel = rsvChannel();\n    rsvSource($channel, 'main');\n    rsvItem('ebook', ['ebook' => 1], StockPolicy::Untracked);\n    rsvReserve($channel, 'order:1', ['ebook' => 1], new DateTimeImmutable('+1 hour'));\n    Event::fake([StockReserved::class]);\n    rsvReserve($channel, 'order:1', ['ebook' => 1], new DateTimeImmutable('+1 hour'));\n    expect(Inventory::reservations()->find('order:1')->generation)->toBe(1);\n    Event::assertNotDispatched(StockReserved::class);\n});\nActual path: place() falls through at line 244, reactivate() runs at line 251, StockReserved is dispatched at line 301. Not executed against PostgreSQL.",
      "confidence": "confirmed"
    },
    {
      "id": "F3",
      "severity": "medium",
      "file": "packages/inventory/src/Availability/Internal/DatabaseAvailability.php",
      "line": 80,
      "summary": "A backorder sellable on a channel with no active source is reported as unlimited, while reserve throws InsufficientStock.",
      "failure_scenario": "Channel web has no stock source. Item tee is backorder, linked to variant:tee. availability check returns null (PriorityAllocation::capacity returns null for an all-backorder need before it looks at sources). reserve(order:1, tee qty 1) builds an empty plan and throws InsufficientStock with available 0. With at least one active source the same backorder reserves past zero on hand, so null is right only when a source exists.",
      "reproduction": "it('does not call a sourceless backorder unlimited', function (): void {\n    $channel = rsvChannel();\n    rsvItem('tee', ['tee' => 1], StockPolicy::Backorder);\n    expect(avlCheck($channel, ['tee' => 1]))->toBe(['tee' => 0])\n        ->and(fn () => rsvReserve($channel, 'order:1', ['tee' => 1]))->toThrow(fn (InsufficientStock $e) => expect($e->available)->toBe(0));\n});\nDerived from DatabaseAvailability.php:77-80 and PriorityAllocation::capacity (null when every component is backorder) plus DatabaseReservations::hold throwing when the plan places nothing. Not executed against PostgreSQL.",
      "confidence": "confirmed"
    }
  ]
}
```