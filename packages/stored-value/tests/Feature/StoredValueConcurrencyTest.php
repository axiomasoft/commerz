<?php

declare(strict_types=1);

use Commerz\Money\Money;
use Commerz\Ports\StoredValue\Data\HoldStoredValue;
use Commerz\Ports\StoredValue\Exceptions\InsufficientStoredValue;
use Commerz\StoredValue\Facades\StoredValue;
use Commerz\StoredValue\GiftCards\Data\IssueGiftCard;
use Commerz\Testkit\Concurrency;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required.');
    }

    $this->artisan('migrate')->assertSuccessful();
});

afterEach(function (): void {
    DB::statement('truncate cmz_stored_value_ledger, cmz_stored_value_holds, cmz_stored_value_accounts cascade');
});

it('prevents concurrent double-spend across processes and upholds INV-SV-001', function (): void {
    $issued = StoredValue::giftCards()->issue(new IssueGiftCard(
        initialBalance: Money::of('50.00', 'USD'),
    ));

    $accountId = $issued->giftCard->id->value;

    $outcomes = Concurrency::run(8, function (int $worker) use ($accountId): string {
        try {
            StoredValue::tender()->hold(new HoldStoredValue(
                accountId: $accountId,
                orderRef: "order:concurrent-{$worker}",
                amount: Money::of('10.00', 'USD'),
                idempotencyKey: "hold:concurrent-{$worker}",
            ));

            return 'held';
        } catch (InsufficientStoredValue) {
            return 'insufficient';
        }
    });

    $counts = array_count_values($outcomes);
    ksort($counts);

    $card = StoredValue::giftCards()->find($issued->giftCard->id);

    expect($counts)->toBe(['held' => 5, 'insufficient' => 3])
        ->and($card)->not->toBeNull()
        ->and($card->balance->amountMinor)->toBe(5_000)
        ->and($card->held->amountMinor)->toBe(5_000)
        ->and($card->available->amountMinor)->toBe(0)
        ->and(StoredValue::ledger()->isBalanced($issued->giftCard->id))->toBeTrue();
})->group('concurrency');
