# Money and measures

Floats never represent money or quantities, in storage or in signatures.

## Money

`Money` is `final readonly` and holds **minor units** (`int`) and a `Currency`.
Storage is `*_minor bigint` plus `currency char(3)`.

```php
$price = Money::of('1000.00', 'RUB');      // from a decimal string
$other = Money::ofMinor(25000, 'RUB');     // from minor units
$total = $price->plus($other)->multiply(2);
$total->toDecimal();                       // '2500.00'
```

Operations on two amounts require the same currency and throw otherwise. There
is no currency conversion.

Intermediate arithmetic — percentages, tax, allocation — uses `brick/math` with
an explicit `RoundingMode`. Rates are **basis points** (`Rate`, `2000` = 20 %).

```php
$net = $price->percentage(new Rate(1000), RoundingMode::HalfUp);        // 10 % of the price
$tax = $price->includedPart(new Rate(2000), RoundingMode::HalfUp);      // the tax carved out of a gross price
```

`MoneyAllocator` splits an amount over weights with the largest-remainder
method: the parts always sum to the whole. It is what spreads an order-level
discount over the lines.

## Quantities and measures

Quantities are `int` in the **base unit** of the stock item (grams,
millilitres, millimetres, pieces). The unit code lives on the stock item; the
factor from sellable to stock item lives on the link. Weighted goods sell in
grams with a sale step.

| Type | Use |
|---|---|
| `Quantity` | an `int` value and a unit; arithmetic checks the unit |
| `Measure` | a decimal string and a unit, for attribute values |
| `LocalizedText` | text by locale tag, never empty, so `resolve()` always has an answer |

## References

References to the host or to another package are `type:id` strings, without
foreign keys: `SellableRef`, `CustomerRef`, `ActorRef`.

```php
SellableRef::of('variant', $id);                  // variant:0198…
SellableRef::fromString('crm.service:audit');
```

Entity ids are UUIDv7 generated in the application (`EntityId` subclasses such
as `ProductId`, `OrderId`); append-only journals use `bigint` identity.

Time is `timestamptz` in UTC; business dates are `date`.
