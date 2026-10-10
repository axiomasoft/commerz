`Verdict: fail`. Two medium defects are reproduced below. The placement protocol itself holds: one cart becomes one order, the idempotency fingerprint is stable, and a duplicate number rolls back without poisoning the host transaction.

## Findings

### 1. Medium — kernel strings longer than the varchar columns abort checkout and cart creation with SQLSTATE 22001

`packages/sales/src/Common/Internal/OrderWriter.php:138` (also `:74`, `:77–78`) and `packages/sales/src/Common/Internal/CartRows.php:85`.

`SellableSnapshot::$name`, `CustomerProfile::$email` / `$name`, and `CommerceContext::$locale` have no length cap (catalog stores the product name as unbounded localized text). The writer copies them into `varchar(191)` and `varchar(35)`. A legal quote then fails inside the savepoint with `QueryException` instead of an order. The savepoint does roll the order and the cart conversion back.

REPRODUCTION (against the current tree, PostgreSQL `commerz_test`):

```php
it('refuses a sellable name the column cannot store', function (): void {
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $this->commerce->sellables->add(slsSnapshot('fake:long', name: str_repeat('A', 192)));
    $cart = slsCartWith($this->web, ['fake:long' => 1]);

    slsPlace($cart); // QueryException SQLSTATE[22001] character varying(191)
});

it('refuses a customer name or email the order columns cannot store', function (): void {
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $this->commerce->sellables->add(slsSnapshot('fake:ok'));
    $ref = CustomerRef::of('crm', 'long');
    $this->commerce->customers->add(new CustomerProfile($ref, [], 'a@b.co', str_repeat('N', 192)));
    $cart = slsCartWith($this->web, ['fake:ok' => 1]);
    slsCarts()->setCustomer(new SetCartCustomer($cart->id, $ref));

    slsPlace($cart); // 22001; str_repeat('N', 191) places. A 193-byte email fails the same way.
});

it('refuses a locale the cart column cannot store', function (): void {
    $locale = 'en'.str_repeat('-abcdef', 6); // 44 bytes, matches CommerceContext's locale grammar

    slsCarts()->create(new CreateCart(CommerceContext::for($this->web, Currency::of('RUB'), $locale)));
    // QueryException SQLSTATE[22001] character varying(35)
});
```

Observed output for the 192-character sellable name: `SQLSTATE[22001] … value too long for type character varying(191)` on `insert into "cmz_order_lines"`, then `cmz_orders` count `0` and the cart still `open`. A following `count()` succeeded, so the host transaction stayed usable. `str_repeat('N', 191)` placed. The same 22001 occurred for a 192-character customer name and for a 193-character email.

### 2. Medium — a store prefix the core accepts makes every sequence number invalid

`packages/sales/src/Numbering/Internal/SequenceOrderNumbers.php:44` and `packages/sales/src/Checkout/Internal/OrderPlacement.php:255`.

`Codes::orderNumberPrefix` allows `[A-Z0-9-]{0,16}`, including a leading hyphen. The generator concatenates that prefix with `nextval`. The placement check and the `orders.number` CHECK both require `[A-Za-z0-9]` as the first character. Prefix `-` yields `-1001`, and `place` throws `InvalidOrderNumber` for every cart of that store.

REPRODUCTION:

```php
it('cannot place when the store prefix starts with a hyphen', function (): void {
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $this->commerce->sellables->add(slsSnapshot('fake:ok'));
    $cart = slsCartWith(slsChannel('hy', '-'), ['fake:ok' => 1]);

    slsPlace($cart); // InvalidOrderNumber: generator 'sequence' returned an invalid number
});
```

Observed: `Commerz\Sales\Numbering\Exceptions\InvalidOrderNumber: Order number generator 'sequence' returned an invalid number.`

## Checked and holding

Ran in this session: the snapshot, after-commit, and duplicate-number tests in `PlacementTest`, plus C1–C3. All passed.

- C1 (same key, 6 processes) returns one order id. C2 (no key) is one success and five `CartAlreadyPlaced`. C3 (four carts) yields four distinct sequence numbers.
- The receipt fingerprint is `cart`, `version`, and `expected_total`. Conversion does not bump `version`. A repeat with the same key returns the same order. A different total on that key throws `IdempotencyConflict` (`PlacementTest`).
- The quote runs before `Transactions::run`. Inside the transaction the cart is locked `FOR UPDATE` and its `version` is compared with the quoted cart. `Channels`, `Stores`, `CustomerDirectory`, and the generator resolve before the transaction. Inside it, the order is `receipt → cart → insert → reserve → consume → convert → journal/outbox`.
- `OrderWriter::insert` is a nested `Transactions::run`. The duplicate-number test, which runs under `RefreshDatabase`'s open transaction, catches `DuplicateOrderNumber` and still queries the database afterward.
- The snapshot test compares line adjustments (including order-level shares and their line ids), delivery, delivery taxes, and totals with the quote, then changes the fake price and name and reads the same `OrderView`.
- Public types outside `Internal` carry `#[Api]` or `#[Spi]`. No `float` or Eloquent types on those signatures. Modules do not import one another's `Internal`; the provider and `SalesPlugin` are the composition root. `QuoteInput` is built only in `CartQuoter`.

## Questions

`knowledge/invariants.json#placement` still says a `LockNotAvailable` or deadlock retries the whole transaction three times with jitter. `sales-design#placement` says the exception propagates and the caller retries. `OrderPlacement` follows the sales design and the item log has no deviation. Which sentence is binding?

## Verdict

Verdict: fail

The concurrency, idempotency, version, savepoint, and snapshot paths that the suite covers behave as the placement protocol describes, including C1–C3 run here. What the suite does not cover is a kernel string that is legal and longer than the sales column, and a store prefix that is legal and starts with a hyphen: both abort checkout, the first with a raw `22001` and the second with `InvalidOrderNumber` on every attempt. Rollback of those failures held in the probes. Full `Sales` suite and PHPStan were not re-run in this review.