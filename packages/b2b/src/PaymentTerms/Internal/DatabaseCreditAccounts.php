<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Internal;

use Commerz\B2b\BusinessUnits\Contracts\BusinessUnits;
use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\Internal\B2bCodec;
use Commerz\B2b\PaymentTerms\Contracts\CreditAccounts;
use Commerz\B2b\PaymentTerms\Data\CaptureCredit;
use Commerz\B2b\PaymentTerms\Data\ConfigureCreditAccount;
use Commerz\B2b\PaymentTerms\Data\CreditAccountId;
use Commerz\B2b\PaymentTerms\Data\CreditAccountView;
use Commerz\B2b\PaymentTerms\Data\CreditHoldId;
use Commerz\B2b\PaymentTerms\Data\CreditHoldView;
use Commerz\B2b\PaymentTerms\Data\ReleaseCredit;
use Commerz\B2b\PaymentTerms\Data\ReserveCredit;
use Commerz\B2b\PaymentTerms\Enums\CreditAccountStatus;
use Commerz\B2b\PaymentTerms\Enums\CreditHoldState;
use Commerz\B2b\PaymentTerms\Exceptions\CreditAccountFrozen;
use Commerz\B2b\PaymentTerms\Exceptions\CreditAccountNotFound;
use Commerz\B2b\PaymentTerms\Exceptions\CreditHoldNotFound;
use Commerz\B2b\PaymentTerms\Exceptions\CreditLimitExceeded;
use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseCreditAccounts implements CreditAccounts
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $commerz,
        private TableNames $tables,
        private Transactions $transactions,
        private BusinessUnits $businessUnits,
        private Clock $clock,
    ) {}

    #[Override]
    public function configure(ConfigureCreditAccount $command): CreditAccountView
    {
        return $this->transactions->run(function () use ($command): CreditAccountView {
            $unit = $this->businessUnits->get($command->businessUnitId);
            $table = $this->tables->table('b2b_credit_accounts');
            $currency = $command->creditLimit->currency->code;
            $now = PgTimestamp::format($this->clock->now());

            $existing = $this->db()
                ->table($table)
                ->where('business_unit_id', $unit->id->value)
                ->where('currency', $currency)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $usedMinor = Rows::int($existing, 'used_credit_minor');
                $reservedMinor = Rows::int($existing, 'reserved_credit_minor');
                $exposureMinor = $usedMinor + $reservedMinor;

                if ($command->creditLimit->amountMinor < $exposureMinor) {
                    throw new CreditLimitExceeded(
                        businessUnitId: $unit->id,
                        requested: Money::ofMinor($exposureMinor, $currency),
                        available: Money::zero($currency),
                        creditLimit: $command->creditLimit,
                    );
                }

                $this->db()
                    ->table($table)
                    ->where('id', Rows::string($existing, 'id'))
                    ->update([
                        'credit_limit_minor' => $command->creditLimit->amountMinor,
                        'status' => $command->status->value,
                        'version' => Rows::int($existing, 'version') + 1,
                        'updated_at' => $now,
                    ]);

                $id = Rows::string($existing, 'id');
            } else {
                $id = CreditAccountId::generate()->value;
                $this->db()->table($table)->insert([
                    'id' => $id,
                    'business_unit_id' => $unit->id->value,
                    'currency' => $currency,
                    'status' => $command->status->value,
                    'credit_limit_minor' => $command->creditLimit->amountMinor,
                    'used_credit_minor' => 0,
                    'reserved_credit_minor' => 0,
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $updated = $this->db()->table($table)->where('id', $id)->first();

            return self::hydrateAccount($updated ?? throw new CreditAccountNotFound($unit->id, $command->creditLimit->currency));
        });
    }

    #[Override]
    public function findForBusinessUnit(BusinessUnitId $businessUnitId, Currency $currency, bool $inheritFromAncestors = true): ?CreditAccountView
    {
        $unit = $this->businessUnits->get($businessUnitId);
        $chainIds = $inheritFromAncestors
            ? array_reverse([...array_map(static fn (BusinessUnitId $aid): string => $aid->value, $unit->ancestorIds()), $unit->id->value])
            : [$unit->id->value];

        $rows = $this->db()
            ->table($this->tables->table('b2b_credit_accounts'))
            ->whereIn('business_unit_id', $chainIds)
            ->where('currency', $currency->code)
            ->get()
            ->all();

        $byUnit = [];
        foreach ($rows as $row) {
            $byUnit[Rows::string($row, 'business_unit_id')] = $row;
        }

        foreach ($chainIds as $candidateUnitId) {
            if (isset($byUnit[$candidateUnitId])) {
                return self::hydrateAccount($byUnit[$candidateUnitId]);
            }
        }

        return null;
    }

    #[Override]
    public function reserve(ReserveCredit $command): CreditHoldView
    {
        return $this->transactions->run(function () use ($command): CreditHoldView {
            $holdsTable = $this->tables->table('b2b_credit_holds');
            $accountsTable = $this->tables->table('b2b_credit_accounts');

            $existingHold = $this->db()
                ->table($holdsTable)
                ->where('reference', $command->reference)
                ->first();

            if ($existingHold !== null) {
                $hydrated = self::hydrateHold($existingHold);
                if (! $hydrated->amount->equals($command->amount)) {
                    throw new InvalidCommand('reference', "credit hold reference '{$command->reference}' already exists with a different amount", 'b2b.idempotency_conflict');
                }

                return $hydrated;
            }

            $resolved = $this->findForBusinessUnit($command->businessUnitId, $command->amount->currency, inheritFromAncestors: true)
                ?? throw new CreditAccountNotFound($command->businessUnitId, $command->amount->currency);

            $accountRow = $this->db()
                ->table($accountsTable)
                ->where('id', $resolved->id->value)
                ->lockForUpdate()
                ->first();

            if ($accountRow === null) {
                throw new CreditAccountNotFound($command->businessUnitId, $command->amount->currency);
            }

            $account = self::hydrateAccount($accountRow);

            if ($account->status !== CreditAccountStatus::Active) {
                throw new CreditAccountFrozen($account->id, $account->businessUnitId);
            }

            if ($command->amount->compare($account->availableCredit) > 0) {
                throw new CreditLimitExceeded(
                    businessUnitId: $command->businessUnitId,
                    requested: $command->amount,
                    available: $account->availableCredit,
                    creditLimit: $account->creditLimit,
                );
            }

            $now = PgTimestamp::format($this->clock->now());
            $affected = $this->db()
                ->table($accountsTable)
                ->where('id', $account->id->value)
                ->where('status', CreditAccountStatus::Active->value)
                ->whereRaw('used_credit_minor + reserved_credit_minor + ? <= credit_limit_minor', [$command->amount->amountMinor])
                ->update([
                    'reserved_credit_minor' => $account->reservedCredit->amountMinor + $command->amount->amountMinor,
                    'version' => $account->version + 1,
                    'updated_at' => $now,
                ]);

            if ($affected !== 1) {
                throw new CreditLimitExceeded(
                    businessUnitId: $command->businessUnitId,
                    requested: $command->amount,
                    available: $account->availableCredit,
                    creditLimit: $account->creditLimit,
                );
            }

            $holdId = CreditHoldId::generate();
            $this->db()->table($holdsTable)->insert([
                'id' => $holdId->value,
                'credit_account_id' => $account->id->value,
                'reference' => $command->reference,
                'currency' => $command->amount->currency->code,
                'amount_minor' => $command->amount->amountMinor,
                'state' => CreditHoldState::Reserved->value,
                'due_at' => $command->dueAt === null ? null : PgTimestamp::format($command->dueAt),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $holdRow = $this->db()->table($holdsTable)->where('id', $holdId->value)->first();

            return self::hydrateHold($holdRow ?? throw new CreditHoldNotFound($command->reference));
        });
    }

    #[Override]
    public function capture(CaptureCredit $command): CreditAccountView
    {
        return $this->transactions->run(function () use ($command): CreditAccountView {
            $holdsTable = $this->tables->table('b2b_credit_holds');
            $accountsTable = $this->tables->table('b2b_credit_accounts');

            $holdRow = $this->db()
                ->table($holdsTable)
                ->where('reference', trim($command->reference))
                ->lockForUpdate()
                ->first();

            if ($holdRow === null) {
                throw new CreditHoldNotFound($command->reference);
            }

            $hold = self::hydrateHold($holdRow);

            $accountRow = $this->db()
                ->table($accountsTable)
                ->where('id', $hold->creditAccountId->value)
                ->lockForUpdate()
                ->first();

            $account = self::hydrateAccount($accountRow ?? throw new CreditHoldNotFound($command->reference));

            if ($hold->state === CreditHoldState::Captured) {
                return $account;
            }

            if ($hold->state !== CreditHoldState::Reserved) {
                throw new InvalidCommand('reference', "cannot capture credit hold in state '{$hold->state->value}'", 'b2b.invalid_credit_hold_state');
            }

            $now = PgTimestamp::format($this->clock->now());

            $this->db()
                ->table($holdsTable)
                ->where('id', $hold->id->value)
                ->update([
                    'state' => CreditHoldState::Captured->value,
                    'updated_at' => $now,
                ]);

            $this->db()
                ->table($accountsTable)
                ->where('id', $account->id->value)
                ->update([
                    'reserved_credit_minor' => $account->reservedCredit->amountMinor - $hold->amount->amountMinor,
                    'used_credit_minor' => $account->usedCredit->amountMinor + $hold->amount->amountMinor,
                    'version' => $account->version + 1,
                    'updated_at' => $now,
                ]);

            $updated = $this->db()->table($accountsTable)->where('id', $account->id->value)->first();

            return self::hydrateAccount($updated ?? throw new CreditHoldNotFound($command->reference));
        });
    }

    #[Override]
    public function release(ReleaseCredit $command): CreditAccountView
    {
        return $this->transactions->run(function () use ($command): CreditAccountView {
            $holdsTable = $this->tables->table('b2b_credit_holds');
            $accountsTable = $this->tables->table('b2b_credit_accounts');

            $holdRow = $this->db()
                ->table($holdsTable)
                ->where('reference', trim($command->reference))
                ->lockForUpdate()
                ->first();

            if ($holdRow === null) {
                throw new CreditHoldNotFound($command->reference);
            }

            $hold = self::hydrateHold($holdRow);

            $accountRow = $this->db()
                ->table($accountsTable)
                ->where('id', $hold->creditAccountId->value)
                ->lockForUpdate()
                ->first();

            $account = self::hydrateAccount($accountRow ?? throw new CreditHoldNotFound($command->reference));

            if ($hold->state === CreditHoldState::Released) {
                return $account;
            }

            if ($hold->state !== CreditHoldState::Reserved) {
                throw new InvalidCommand('reference', "cannot release credit hold in state '{$hold->state->value}'", 'b2b.invalid_credit_hold_state');
            }

            $now = PgTimestamp::format($this->clock->now());

            $this->db()
                ->table($holdsTable)
                ->where('id', $hold->id->value)
                ->update([
                    'state' => CreditHoldState::Released->value,
                    'updated_at' => $now,
                ]);

            $this->db()
                ->table($accountsTable)
                ->where('id', $account->id->value)
                ->update([
                    'reserved_credit_minor' => $account->reservedCredit->amountMinor - $hold->amount->amountMinor,
                    'version' => $account->version + 1,
                    'updated_at' => $now,
                ]);

            $updated = $this->db()->table($accountsTable)->where('id', $account->id->value)->first();

            return self::hydrateAccount($updated ?? throw new CreditHoldNotFound($command->reference));
        });
    }

    #[Override]
    public function repay(BusinessUnitId $businessUnitId, Money $amount): CreditAccountView
    {
        if (! $amount->isPositive()) {
            throw new InvalidCommand('amount', 'repayment amount must be positive', 'b2b.invalid_amount');
        }

        return $this->transactions->run(function () use ($businessUnitId, $amount): CreditAccountView {
            $resolved = $this->findForBusinessUnit($businessUnitId, $amount->currency, inheritFromAncestors: true)
                ?? throw new CreditAccountNotFound($businessUnitId, $amount->currency);

            $accountsTable = $this->tables->table('b2b_credit_accounts');
            $accountRow = $this->db()
                ->table($accountsTable)
                ->where('id', $resolved->id->value)
                ->lockForUpdate()
                ->first();

            $account = self::hydrateAccount($accountRow ?? throw new CreditAccountNotFound($businessUnitId, $amount->currency));
            $nextUsed = max(0, $account->usedCredit->amountMinor - $amount->amountMinor);

            $this->db()
                ->table($accountsTable)
                ->where('id', $account->id->value)
                ->update([
                    'used_credit_minor' => $nextUsed,
                    'version' => $account->version + 1,
                    'updated_at' => PgTimestamp::format($this->clock->now()),
                ]);

            $updated = $this->db()->table($accountsTable)->where('id', $account->id->value)->first();

            return self::hydrateAccount($updated ?? throw new CreditAccountNotFound($businessUnitId, $amount->currency));
        });
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->commerz->connection);
    }

    private static function hydrateAccount(object $row): CreditAccountView
    {
        $currency = Currency::of(Rows::string($row, 'currency'));

        return new CreditAccountView(
            id: CreditAccountId::fromString(Rows::string($row, 'id')),
            businessUnitId: BusinessUnitId::fromString(Rows::string($row, 'business_unit_id')),
            currency: $currency,
            status: CreditAccountStatus::from(Rows::string($row, 'status')),
            creditLimit: Money::ofMinor(Rows::int($row, 'credit_limit_minor'), $currency),
            usedCredit: Money::ofMinor(Rows::int($row, 'used_credit_minor'), $currency),
            reservedCredit: Money::ofMinor(Rows::int($row, 'reserved_credit_minor'), $currency),
            version: Rows::int($row, 'version'),
            updatedAt: B2bCodec::parseDate(Rows::string($row, 'updated_at')),
        );
    }

    private static function hydrateHold(object $row): CreditHoldView
    {
        return new CreditHoldView(
            id: CreditHoldId::fromString(Rows::string($row, 'id')),
            creditAccountId: CreditAccountId::fromString(Rows::string($row, 'credit_account_id')),
            reference: Rows::string($row, 'reference'),
            amount: Money::ofMinor(Rows::int($row, 'amount_minor'), Rows::string($row, 'currency')),
            state: CreditHoldState::from(Rows::string($row, 'state')),
            dueAt: B2bCodec::parseNullableDate(Rows::nullableString($row, 'due_at')),
            createdAt: B2bCodec::parseDate(Rows::string($row, 'created_at')),
        );
    }
}
