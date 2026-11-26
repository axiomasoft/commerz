<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Errors\IdempotencyConflict;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Money\Rate;
use Commerz\Money\RoundingMode;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Ports\Sellables\Contracts\SellableProvider;
use Commerz\Ports\StoredValue\Contracts\StoredValueTender;
use Commerz\Ports\StoredValue\Data\CaptureStoredValue;
use Commerz\Ports\StoredValue\Data\HoldStoredValue;
use Commerz\Ports\StoredValue\Data\RefundToStoredValue;
use Commerz\Ports\StoredValue\Data\ReleaseStoredValue;
use Commerz\Ports\StoredValue\Data\TenderInstrument;
use Commerz\Ports\StoredValue\Exceptions\InsufficientStoredValue;
use Commerz\Ports\StoredValue\Exceptions\StoredValueUnavailable;
use Commerz\Quotes\Contracts\QuotePipeline;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Data\TaxLine;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;
use Commerz\StoredValue\Facades\StoredValue;
use Commerz\StoredValue\GiftCards\Data\GiftCardCode;
use Commerz\StoredValue\GiftCards\Data\IssueGiftCard;
use Commerz\StoredValue\Ledger\Enums\AccountStatus;
use Commerz\StoredValue\Ledger\Enums\LedgerDirection;
use Commerz\StoredValue\Ledger\Enums\LedgerEntrySide;
use Commerz\StoredValue\Ledger\Enums\LedgerEntryType;
use Commerz\StoredValue\Wallets\Data\CreditWallet;
use Commerz\Stores\Data\ChannelId;
use Commerz\Testkit\Fakes\FakePrices;
use Commerz\Testkit\Fakes\FakeSellables;
use Commerz\Testkit\Fakes\FrozenClock;
use Commerz\Time\Contracts\Clock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

final readonly class SvFlatVatStage implements QuoteStage
{
    public function __construct(private int $rateBasisPoints) {}

    public function key(): string
    {
        return 'test.vat';
    }

    public function anchor(): QuoteAnchor
    {
        return QuoteAnchor::Tax;
    }

    public function position(): StagePosition
    {
        return StagePosition::At;
    }

    public function apply(QuoteState $state): QuoteState
    {
        $rate = Rate::bp($this->rateBasisPoints);

        foreach ($state->lines as $line) {
            $net = $state->lineNet($line->lineId);
            $taxAmount = $net->percentage($rate, RoundingMode::HalfUp);
            $state = $state->withTaxLine(new TaxLine(
                target: AdjustmentTarget::Line,
                lineId: $line->lineId,
                code: 'VAT20',
                rate: $rate,
                amount: $taxAmount,
                included: false,
            ));
        }

        return $state;
    }
}

final class SvVatQuotePlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.sv-vat-quote', '1.0.0', ['commerz.core']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(SellableProvider::KIND, (new FakeSellables('product'))->add(
            FakeSellables::snapshot('product:widget', Money::of('100.00', 'EUR')),
        ));
        $registrar->contribute(QuoteStage::KIND, new SvFlatVatStage(2000));
    }
}

beforeEach(function (): void {
    config()->set('database.connections.sv_sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('commerz.connection', 'sv_sqlite');
    DB::purge('sv_sqlite');

    $db = DB::connection('sv_sqlite');
    $db->statement('PRAGMA foreign_keys = ON');

    $db->statement(<<<'SQL'
        create table cmz_stored_value_accounts (
            id text primary key,
            kind text not null check (kind in ('gift_card', 'wallet')),
            status text not null default 'active' check (status in ('active', 'frozen', 'expired', 'voided')),
            currency text not null,
            code_hash text unique,
            code_last4 text,
            customer_ref text,
            initial_minor integer not null default 0 check (initial_minor >= 0),
            balance_minor integer not null default 0 check (balance_minor >= 0),
            held_minor integer not null default 0 check (held_minor >= 0),
            version integer not null default 0 check (version >= 0),
            expires_at text,
            metadata text not null default '{}',
            created_at text not null,
            updated_at text not null,
            check (balance_minor - held_minor >= 0),
            check (kind <> 'gift_card' or (code_hash is not null and length(code_hash) = 64)),
            check (kind <> 'wallet' or (customer_ref is not null and code_hash is null))
        )
    SQL);

    $db->statement("create unique index cmz_stored_value_accounts_wallet_uq on cmz_stored_value_accounts (customer_ref, currency) where kind = 'wallet'");

    $db->statement(<<<'SQL'
        create table cmz_stored_value_holds (
            id text primary key,
            account_id text not null references cmz_stored_value_accounts(id),
            order_ref text not null,
            idempotency_key text not null unique,
            currency text not null,
            amount_minor integer not null check (amount_minor > 0),
            captured_minor integer not null default 0 check (captured_minor >= 0),
            released_minor integer not null default 0 check (released_minor >= 0),
            state text not null default 'held' check (state in ('held', 'captured', 'released', 'expired')),
            expires_at text,
            created_at text not null,
            updated_at text not null,
            check (captured_minor + released_minor <= amount_minor)
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_stored_value_ledger (
            id integer primary key autoincrement,
            journal_id text not null,
            account_id text not null references cmz_stored_value_accounts(id),
            hold_id text references cmz_stored_value_holds(id),
            entry_type text not null check (entry_type in ('issue', 'hold', 'release', 'capture', 'refund_credit', 'expire')),
            entry_side text not null check (entry_side in ('account', 'counterpart')),
            direction text not null check (direction in ('debit', 'credit')),
            ledger_account text not null,
            currency text not null,
            amount_minor integer not null check (amount_minor > 0),
            balance_delta_minor integer not null default 0,
            held_delta_minor integer not null default 0,
            balance_after_minor integer not null check (balance_after_minor >= 0),
            held_after_minor integer not null check (held_after_minor >= 0),
            reference text,
            idempotency_key text,
            actor_ref text,
            reason text,
            occurred_at text not null,
            check (balance_after_minor - held_after_minor >= 0),
            unique (entry_type, entry_side, idempotency_key)
        )
    SQL);

    app()->singleton(Transactions::class, static fn (): Transactions => new class($db) implements Transactions
    {
        public function __construct(private readonly ConnectionInterface $db) {}

        public function run(callable $work, int $lockTimeoutMs = 3000): mixed
        {
            return $this->db->transaction(static fn (): mixed => $work());
        }

        public function inTransaction(): bool
        {
            return $this->db->transactionLevel() > 0;
        }
    });

    app()->singleton(Clock::class, static fn (): Clock => new FrozenClock(new DateTimeImmutable('2026-11-26T12:00:00Z')));
});

it('issues gift cards storing only SHA-256 code_hash, never plaintext, and supports lookup and lifecycle transitions', function (): void {
    $issued = StoredValue::giftCards()->issue(new IssueGiftCard(
        initialBalance: Money::of('100.00', 'USD'),
        recipient: CustomerRef::of('user', '10'),
        reference: 'sale:gc-1',
        idempotencyKey: 'issue:gc-1',
        actorRef: 'admin:1',
    ));

    $rawRow = DB::connection('sv_sqlite')
        ->table('cmz_stored_value_accounts')
        ->where('id', $issued->giftCard->id->value)
        ->first();

    expect($rawRow)->not->toBeNull()
        ->and($rawRow->code_hash)->toBe(GiftCardCode::hash($issued->plainCode))
        ->and($rawRow->code_hash)->toHaveLength(64)
        ->and($rawRow->code_hash)->not->toBe($issued->plainCode)
        ->and($rawRow->code_last4)->toBe(GiftCardCode::last4($issued->plainCode));

    $found = StoredValue::giftCards()->findByCode(strtolower(str_replace('-', ' ', $issued->plainCode)));
    expect($found)->not->toBeNull()
        ->and($found->id->value)->toBe($issued->giftCard->id->value)
        ->and($found->available->equals(Money::of('100.00', 'USD')))->toBeTrue();

    $frozen = StoredValue::giftCards()->freeze($issued->giftCard->id, 'suspected fraud');
    expect($frozen->status)->toBe(AccountStatus::Frozen);

    expect(fn () => StoredValue::tender()->hold(new HoldStoredValue(
        accountId: $issued->giftCard->id->value,
        orderRef: 'order:frozen-1',
        amount: Money::of('10.00', 'USD'),
        idempotencyKey: 'hold:frozen-1',
    )))->toThrow(StoredValueUnavailable::class, 'frozen');

    $unfrozen = StoredValue::giftCards()->unfreeze($issued->giftCard->id);
    expect($unfrozen->status)->toBe(AccountStatus::Active);

    $voided = StoredValue::giftCards()->void($issued->giftCard->id, 'issued in error');
    expect($voided->status)->toBe(AccountStatus::Voided)
        ->and($voided->balance->isZero())->toBeTrue()
        ->and(StoredValue::ledger()->isBalanced($issued->giftCard->id))->toBeTrue();
});

it('records balanced double-entry ledger entries across issue, hold, release, capture, refund_credit, and expire', function (): void {
    /** @var FrozenClock $clock */
    $clock = app(Clock::class);

    $issued = StoredValue::giftCards()->issue(new IssueGiftCard(
        initialBalance: Money::of('100.00', 'EUR'),
        expiresAt: new DateTimeImmutable('2026-12-01T00:00:00Z'),
        reference: 'order:gc-purchase-1',
        idempotencyKey: 'issue:1',
    ));

    $accountId = $issued->giftCard->id;

    // 1. Hold 60.00 (idempotent replay returns identical hold)
    $hold = StoredValue::tender()->hold(new HoldStoredValue(
        accountId: $accountId->value,
        orderRef: 'order:1001',
        amount: Money::of('60.00', 'EUR'),
        idempotencyKey: 'hold:1001',
    ));
    $holdReplay = StoredValue::tender()->hold(new HoldStoredValue(
        accountId: $accountId->value,
        orderRef: 'order:1001',
        amount: Money::of('60.00', 'EUR'),
        idempotencyKey: 'hold:1001',
    ));
    expect($holdReplay->holdId)->toBe($hold->holdId);

    // 2. Partial capture 40.00 + release remaining 20.00
    $capture = StoredValue::tender()->capture(new CaptureStoredValue(
        holdId: $hold->holdId,
        reference: 'order:1001',
        idempotencyKey: 'capture:1001',
        amount: Money::of('40.00', 'EUR'),
    ));
    expect($capture->balanceAfter->equals(Money::of('60.00', 'EUR')))->toBeTrue()
        ->and($capture->heldAfter->equals(Money::of('20.00', 'EUR')))->toBeTrue();

    StoredValue::tender()->release(new ReleaseStoredValue(
        holdId: $hold->holdId,
        idempotencyKey: 'release:1001',
        amount: Money::of('20.00', 'EUR'),
    ));

    // 3. Refund credit 15.00 back onto account
    $credit = StoredValue::tender()->refundCredit(new RefundToStoredValue(
        amount: Money::of('15.00', 'EUR'),
        reference: 'refund:1001',
        idempotencyKey: 'refund:1001',
        accountId: $accountId->value,
    ));
    expect($credit->balanceAfter->equals(Money::of('75.00', 'EUR')))->toBeTrue();

    // 4. Advance clock past expiry and expire due balance
    $clock->set(new DateTimeImmutable('2026-12-02T00:00:00Z'));
    expect(StoredValue::giftCards()->expireDue())->toBe(1);

    $final = StoredValue::giftCards()->find($accountId);
    expect($final)->not->toBeNull()
        ->and($final->status)->toBe(AccountStatus::Expired)
        ->and($final->balance->isZero())->toBeTrue()
        ->and($final->held->isZero())->toBeTrue();

    // Verify all 6 double-entry event types exist and every journal strictly balances debits == credits
    $entries = StoredValue::ledger()->entriesForAccount($accountId);
    $accountEntries = StoredValue::ledger()->entriesForAccount($accountId, LedgerEntrySide::Account);

    expect($entries)->toHaveCount(12) // 6 journals x 2 legs
        ->and(array_map(static fn ($e): string => $e->entryType->value, $accountEntries))->toBe([
            LedgerEntryType::Issue->value,
            LedgerEntryType::Hold->value,
            LedgerEntryType::Capture->value,
            LedgerEntryType::Release->value,
            LedgerEntryType::RefundCredit->value,
            LedgerEntryType::Expire->value,
        ])
        ->and(StoredValue::ledger()->isBalanced($accountId))->toBeTrue();

    $journalIds = array_values(array_unique(array_map(static fn ($e): string => $e->journalId, $entries)));
    expect($journalIds)->toHaveCount(6);

    foreach ($journalIds as $journalId) {
        $legs = StoredValue::ledger()->journalEntries($journalId);
        expect($legs)->toHaveCount(2);

        $debits = 0;
        $credits = 0;
        foreach ($legs as $leg) {
            if ($leg->direction === LedgerDirection::Debit) {
                $debits += $leg->amount->amountMinor;
            } else {
                $credits += $leg->amount->amountMinor;
            }
        }
        expect($debits)->toBe($credits);
    }
});

it('enforces INV-SV-001 (balance_minor - held_minor >= 0) across double-spend attempts, expiry with open holds, and DB check constraints', function (): void {
    /** @var FrozenClock $clock */
    $clock = app(Clock::class);
    $customer = CustomerRef::of('user', '77');

    $credit = StoredValue::wallets()->credit(new CreditWallet(
        customer: $customer,
        amount: Money::of('100.00', 'USD'),
        reference: 'refund:order-5',
        idempotencyKey: 'wallet-credit:1',
    ));

    // Replaying same idempotency key with different amount throws IdempotencyConflict
    expect(fn () => StoredValue::wallets()->credit(new CreditWallet(
        customer: $customer,
        amount: Money::of('50.00', 'USD'),
        reference: 'refund:order-5',
        idempotencyKey: 'wallet-credit:1',
    )))->toThrow(IdempotencyConflict::class);

    // Hold 60.00 succeeds; second competing hold of 60.00 fails cleanly with InsufficientStoredValue
    $hold1 = StoredValue::tender()->hold(new HoldStoredValue(
        accountId: $credit->accountId,
        orderRef: 'order:race-1',
        amount: Money::of('60.00', 'USD'),
        idempotencyKey: 'hold:race-1',
    ));

    expect(fn () => StoredValue::tender()->hold(new HoldStoredValue(
        accountId: $credit->accountId,
        orderRef: 'order:race-2',
        amount: Money::of('60.00', 'USD'),
        idempotencyKey: 'hold:race-2',
    )))->toThrow(InsufficientStoredValue::class);

    // Exact remaining 40.00 succeeds, leaving available == 0
    $hold2 = StoredValue::tender()->hold(new HoldStoredValue(
        accountId: $credit->accountId,
        orderRef: 'order:race-3',
        amount: Money::of('40.00', 'USD'),
        idempotencyKey: 'hold:race-3',
    ));

    // Even 1 minor unit more fails cleanly
    expect(fn () => StoredValue::tender()->hold(new HoldStoredValue(
        accountId: $credit->accountId,
        orderRef: 'order:race-4',
        amount: Money::ofMinor(1, 'USD'),
        idempotencyKey: 'hold:race-4',
    )))->toThrow(InsufficientStoredValue::class);

    $wallet = StoredValue::wallets()->findByCustomer($customer, Currency::of('USD'));
    expect($wallet)->not->toBeNull()
        ->and($wallet->balance->amountMinor)->toBe(10_000)
        ->and($wallet->held->amountMinor)->toBe(10_000)
        ->and($wallet->available->amountMinor)->toBe(0);

    // Direct SQL attempt to violate INV-SV-001 (held_minor > balance_minor) is blocked by CHECK constraint
    expect(fn () => DB::connection('sv_sqlite')
        ->table('cmz_stored_value_accounts')
        ->where('id', $credit->accountId)
        ->update(['held_minor' => 10_001]))->toThrow(QueryException::class);

    // Gift card with active hold expiring: only unheld balance expires, active hold stays covered (INV-SV-001)
    $gc = StoredValue::giftCards()->issue(new IssueGiftCard(
        initialBalance: Money::of('100.00', 'USD'),
        expiresAt: new DateTimeImmutable('2026-11-27T00:00:00Z'),
    ));

    StoredValue::tender()->hold(new HoldStoredValue(
        accountId: $gc->giftCard->id->value,
        orderRef: 'order:open-hold',
        amount: Money::of('30.00', 'USD'),
        idempotencyKey: 'hold:open-hold',
    ));

    $clock->set(new DateTimeImmutable('2026-11-28T00:00:00Z'));
    expect(StoredValue::giftCards()->expireDue())->toBe(1);

    $afterExpiry = StoredValue::giftCards()->find($gc->giftCard->id);
    expect($afterExpiry)->not->toBeNull()
        ->and($afterExpiry->balance->equals(Money::of('30.00', 'USD')))->toBeTrue()
        ->and($afterExpiry->held->equals(Money::of('30.00', 'USD')))->toBeTrue()
        ->and($afterExpiry->available->isZero())->toBeTrue()
        ->and($afterExpiry->balance->amountMinor - $afterExpiry->held->amountMinor)->toBeGreaterThanOrEqual(0)
        ->and(StoredValue::ledger()->isBalanced($gc->giftCard->id))->toBeTrue();

    expect($hold1->holdId)->not->toBe($hold2->holdId);
});

it('deducts gift cards and store credit from grandTotal AFTER taxes and NEVER reduces taxable VAT base', function (): void {
    config()->set('commerz.plugins', [SvVatQuotePlugin::class]);
    app()->singleton(PriceResolver::class, static fn (): FakePrices => (new FakePrices)->set(
        SellableRef::of('product', 'widget'),
        Money::of('100.00', 'EUR'),
    ));

    $context = CommerceContext::for(ChannelId::generate(), Currency::of('EUR'));
    $quote = app(QuotePipeline::class)->run(new QuoteInput(
        context: $context,
        lines: [new QuoteLineInput('line-1', FakeSellables::snapshot('product:widget', Money::of('100.00', 'EUR')), 1)],
    ));

    // Pre-tender quote: 100.00 net subtotal + 20% VAT (20.00) = 120.00 grandTotal
    expect($quote->totals->subtotal->equals(Money::of('100.00', 'EUR')))->toBeTrue()
        ->and($quote->totals->taxTotal->equals(Money::of('20.00', 'EUR')))->toBeTrue()
        ->and($quote->totals->total->equals(Money::of('120.00', 'EUR')))->toBeTrue();

    $gc = StoredValue::giftCards()->issue(new IssueGiftCard(Money::of('50.00', 'EUR')));
    $customer = CustomerRef::of('user', '55');
    StoredValue::wallets()->credit(new CreditWallet(
        customer: $customer,
        amount: Money::of('30.00', 'EUR'),
        reference: 'refund:prev',
        idempotencyKey: 'vat-test-wallet:1',
    ));

    /** @var StoredValueTender $tender */
    $tender = app(StoredValueTender::class);
    $settlement = $tender->applyToQuote($quote, [
        TenderInstrument::giftCardCode($gc->plainCode),
        TenderInstrument::walletFor($customer),
    ]);

    // Taxable VAT base is STILL 100.00 and VAT is STILL 20.00 (NEVER 20% of 50.00 or 20% of 20.00!)
    expect($quote->totals->taxTotal->equals(Money::of('20.00', 'EUR')))->toBeTrue()
        ->and($settlement->taxTotal->equals(Money::of('20.00', 'EUR')))->toBeTrue()
        ->and($settlement->grandTotal->equals(Money::of('120.00', 'EUR')))->toBeTrue()
        ->and($settlement->tenderTotal->equals(Money::of('80.00', 'EUR')))->toBeTrue()
        ->and($settlement->payableTotal->equals(Money::of('40.00', 'EUR')))->toBeTrue()
        ->and($settlement->allocations)->toHaveCount(2)
        ->and($settlement->allocations[0]->amount->equals(Money::of('50.00', 'EUR')))->toBeTrue()
        ->and($settlement->allocations[1]->amount->equals(Money::of('30.00', 'EUR')))->toBeTrue()
        ->and($settlement->rejections)->toBe([]);

    // Gift card larger than grandTotal caps at 120.00, leaving 80.00 on card and tax STILL 20.00
    $bigCard = StoredValue::giftCards()->issue(new IssueGiftCard(Money::of('200.00', 'EUR')));
    $fullCoverage = $tender->applyToQuote($quote, [
        TenderInstrument::giftCardCode($bigCard->plainCode),
    ]);

    expect($fullCoverage->taxTotal->equals(Money::of('20.00', 'EUR')))->toBeTrue()
        ->and($fullCoverage->grandTotal->equals(Money::of('120.00', 'EUR')))->toBeTrue()
        ->and($fullCoverage->tenderTotal->equals(Money::of('120.00', 'EUR')))->toBeTrue()
        ->and($fullCoverage->payableTotal->isZero())->toBeTrue()
        ->and($fullCoverage->allocations[0]->remainingBalance->equals(Money::of('80.00', 'EUR')))->toBeTrue();
});
