Verdict: fail

Two medium defects: a normalized per-unit `line.fixed_off` can abort the quote, and a non-positive `cart.subtotal_at_least` amount qualifies every ordinary cart. Golden-path behavior matches the design. Promotions Pest is 170 passed. PHPStan at max on `packages/promotions/src/Rules/Internal` and `PromotionsPlugin.php` reports no errors. A 10 025-pair check of `UnitPool` against `MoneyAllocator` matched, and 200 random `buy_x_get_y` carts matched an independent allocator oracle (G9–G12, D15 line nets, string line ids such as `'1'`).

## Findings

### 1. Medium — `packages/promotions/src/Rules/Internal/Effects/LineFixedOff.php:64`

`normalize` accepts a per-unit amount, and `apply` then multiplies it by the line quantity before clamping, so any product past int64 throws `money.overflow` instead of discounting `min(amount × quantity, lineNet)`.

`normalize` stores `amount_minor` `4611686018427387904` with `per=unit`. On a line `10.00 × 2` (net `20.00`), `apply` throws. The same amount with `per=line` returns `20.00`.

```php
it('clamps a per-unit fixed discount when amount × quantity exceeds int64', function (): void {
    $effect = new LineFixedOff;
    $stored = $effect->normalize(RuleParams::fromInput([
        'amount' => Money::ofMinor(intdiv(PHP_INT_MAX, 2) + 1, 'RUB'),
        'per' => 'unit',
    ]), RuleStates::scope())->toArray();
    $state = RuleStates::state([['id' => '1', 'unit' => '10.00', 'qty' => 2]]);

    expect(effDescribe($effect->apply(RuleStates::effect($state, $stored))))->toBe(['line 1: 20.00']);
});
```

Observed: `Commerz\Errors\InvalidCommand: Invalid amount: result does not fit int64.`

### 2. Medium — `packages/promotions/src/Rules/Internal/Conditions/CartSubtotalAtLeast.php:63`

`evaluate` reads `amount` with `money()` and never requires it to be positive, so a stored `0` or negative minimum passes for every cart whose net is at least that amount. `normalize` rejects non-positive amounts; `RuleCodec::decode` does not run `normalize` again when the stored version is current.

```php
it('rejects a non-positive minimum at evaluate', function (): void {
    $state = RuleStates::state([['id' => '1', 'unit' => '10.00', 'qty' => 2]]);
    $outcome = (new CartSubtotalAtLeast)->evaluate(RuleStates::condition($state, [
        'amount' => Money::ofMinor(0, 'RUB'),
        'lines' => 'all',
    ]));

    expect($outcome->passed)->toBeFalse();
});
```

Observed: `passed=yes` for both `amount_minor` `0` and `-1` on that `20.00` cart. A USD amount on the same cart throws `InvalidRuleConfig` (`currency must be RUB, got USD`).

### 3. Low — `packages/promotions/src/Rules/Internal/Effects/UnitPool.php:43`

The running unit count is a raw PHP `+=`. Two lines whose quantities sum past `PHP_INT_MAX` make that sum a float, and the `int $units` constructor throws `TypeError`. `buy_x_get_y` calls this from `BuyXGetY.php:72`. `QuoteLineInput` allows each quantity.

```php
$state = RuleStates::state([
    ['id' => '1', 'unit' => '0.00', 'qty' => PHP_INT_MAX],
    ['id' => '2', 'unit' => '0.00', 'qty' => PHP_INT_MAX],
]);
(new BuyXGetY)->apply(RuleStates::effect($state, [
    'buy' => 1, 'get' => 1, 'rateBp' => 10000, 'maxApplications' => null,
]));
```

Observed: `TypeError: UnitPool::__construct(): Argument #2 ($units) must be of type int, float given` at `UnitPool.php:48`.

### 4. Low — `packages/promotions/src/Rules/Internal/Effects/UnitPool.php:29`

A negative line net aborts `buy_x_get_y` with `InvalidCommand` (`promotions.invalid_unit_pool`). The other built-in effects return no discount for a non-positive base (`LinePercentOff` returns `[]` for the same state). `QuoteState::withAdjustment` allows the net to go negative before totals.

```php
$state = RuleStates::state([['id' => '1', 'unit' => '10.00']])
    ->withAdjustment(Adjustment::line('1', Money::of('-15.00', 'RUB'), 't', 't'));
(new BuyXGetY)->apply(RuleStates::effect($state, [
    'buy' => 1, 'get' => 1, 'rateBp' => 10000, 'maxApplications' => null,
]));
```

Observed: `InvalidCommand: Invalid lines: a line needs a non-negative net and a positive quantity.` Line percent on that state (`net=-5.00`) returns `[]`.