<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Data\Compiler\ConstraintNames;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $accounts = $this->create('stored_value_accounts', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('kind', 16);
            $table->string('status', 16)->default('active');
            $table->char('currency', 3);
            $table->char('code_hash', 64)->nullable();
            $table->string('code_last4', 8)->nullable();
            $table->string('customer_ref', 191)->nullable();
            $table->bigInteger('initial_minor')->default(0);
            $table->bigInteger('balance_minor')->default(0);
            $table->bigInteger('held_minor')->default(0);
            $table->bigInteger('version')->default(0);
            $table->timestampTz('expires_at', 6)->nullable();
            $table->jsonb('metadata')->default('{}');
            $this->timestampsTz($table);
            $this->unique($table, 'code_hash');
            $this->index($table, ['customer_ref', 'currency']);
            $this->index($table, ['status', 'expires_at']);
        });

        $this->check($accounts, 'kind_valid', "kind in ('gift_card', 'wallet')");
        $this->check($accounts, 'status_valid', "status in ('active', 'frozen', 'expired', 'voided')");
        $this->check($accounts, 'initial_non_negative', 'initial_minor >= 0');
        $this->check($accounts, 'balance_non_negative', 'balance_minor >= 0');
        $this->check($accounts, 'held_non_negative', 'held_minor >= 0');
        $this->check($accounts, 'inv_sv_001', 'balance_minor - held_minor >= 0');
        $this->check($accounts, 'version_non_negative', 'version >= 0');
        $this->check($accounts, 'gift_card_has_code_hash', "kind <> 'gift_card' or (code_hash is not null and char_length(code_hash) = 64)");
        $this->check($accounts, 'wallet_has_customer', "kind <> 'wallet' or (customer_ref is not null and code_hash is null)");

        if ($this->db()->getDriverName() === 'pgsql') {
            $walletUnique = ConstraintNames::unique($accounts, ['customer_ref', 'currency']);
            $this->db()->statement(
                "create unique index \"{$walletUnique}\" on \"{$accounts}\" (customer_ref, currency) where kind = 'wallet'",
            );
        }

        $holds = $this->create('stored_value_holds', function (Blueprint $table) use ($accounts): void {
            $this->uuidPrimary($table);
            $table->uuid('account_id');
            $table->string('order_ref', 191);
            $table->string('idempotency_key', 191);
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->bigInteger('captured_minor')->default(0);
            $table->bigInteger('released_minor')->default(0);
            $table->string('state', 16)->default('held');
            $table->timestampTz('expires_at', 6)->nullable();
            $this->timestampsTz($table);
            $this->unique($table, 'idempotency_key');
            $this->index($table, ['account_id', 'state']);
            $this->index($table, 'order_ref');
            $this->foreign($table, 'account_id')->references('id')->on($accounts)->restrictOnDelete();
        });

        $this->check($holds, 'amount_positive', 'amount_minor > 0');
        $this->check($holds, 'captured_non_negative', 'captured_minor >= 0');
        $this->check($holds, 'released_non_negative', 'released_minor >= 0');
        $this->check($holds, 'within_amount', 'captured_minor + released_minor <= amount_minor');
        $this->check($holds, 'state_valid', "state in ('held', 'captured', 'released', 'expired')");

        $ledger = $this->create('stored_value_ledger', function (Blueprint $table) use ($accounts, $holds): void {
            $this->identityPrimary($table);
            $table->uuid('journal_id');
            $table->uuid('account_id');
            $table->uuid('hold_id')->nullable();
            $table->string('entry_type', 24);
            $table->string('entry_side', 16);
            $table->string('direction', 8);
            $table->string('ledger_account', 191);
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->bigInteger('balance_delta_minor')->default(0);
            $table->bigInteger('held_delta_minor')->default(0);
            $table->bigInteger('balance_after_minor');
            $table->bigInteger('held_after_minor');
            $table->string('reference', 191)->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->string('actor_ref', 191)->nullable();
            $table->string('reason', 191)->nullable();
            $table->timestampTz('occurred_at', 6)->useCurrent();
            $this->index($table, ['account_id', 'id']);
            $this->index($table, 'journal_id');
            $this->index($table, 'reference');
            $this->unique($table, ['entry_type', 'entry_side', 'idempotency_key']);
            $this->foreign($table, 'account_id')->references('id')->on($accounts)->restrictOnDelete();
            $this->foreign($table, 'hold_id')->references('id')->on($holds)->restrictOnDelete();
        });

        $this->check($ledger, 'entry_type_valid', "entry_type in ('issue', 'hold', 'release', 'capture', 'refund_credit', 'expire')");
        $this->check($ledger, 'entry_side_valid', "entry_side in ('account', 'counterpart')");
        $this->check($ledger, 'direction_valid', "direction in ('debit', 'credit')");
        $this->check($ledger, 'amount_positive', 'amount_minor > 0');
        $this->check($ledger, 'balance_after_non_negative', 'balance_after_minor >= 0');
        $this->check($ledger, 'held_after_non_negative', 'held_after_minor >= 0');
        $this->check($ledger, 'after_inv_sv_001', 'balance_after_minor - held_after_minor >= 0');

        if ($this->db()->getDriverName() === 'pgsql') {
            $fn = ConstraintNames::shorten("{$ledger}_immutable_fn");
            $trg = ConstraintNames::shorten("{$ledger}_immutable_tg");

            $this->db()->statement(<<<SQL
                create or replace function "{$fn}"() returns trigger as $$
                begin
                    raise exception 'cmz_stored_value_ledger is immutable and append-only';
                end;
                $$ language plpgsql
            SQL);

            $this->db()->statement(<<<SQL
                create trigger "{$trg}"
                before update or delete on "{$ledger}"
                for each row execute function "{$fn}"()
            SQL);
        }
    }

    public function down(): void
    {
        if ($this->db()->getDriverName() === 'pgsql') {
            $ledger = $this->table('stored_value_ledger');
            $fn = ConstraintNames::shorten("{$ledger}_immutable_fn");
            $trg = ConstraintNames::shorten("{$ledger}_immutable_tg");

            $this->db()->statement("drop trigger if exists \"{$trg}\" on \"{$ledger}\"");
            $this->db()->statement("drop function if exists \"{$fn}\"()");
        }

        $this->drop('stored_value_ledger');
        $this->drop('stored_value_holds');
        $this->drop('stored_value_accounts');
    }
};
