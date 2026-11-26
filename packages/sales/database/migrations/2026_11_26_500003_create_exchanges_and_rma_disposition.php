<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Data\Compiler\ConstraintNames;

return new class extends CommerzMigration
{
    private const string DISPOSITIONS = "'restock', 'damaged_write_off'";

    private const string REASONS = "'defective', 'damaged_in_transit', 'wrong_item', 'not_as_described', 'changed_mind', 'size_or_fit', 'other'";

    public function up(): void
    {
        $orders = $this->table('orders');
        $orderLines = $this->table('order_lines');
        $returns = $this->table('returns');
        $returnLines = $this->table('return_lines');
        $fulfillments = $this->table('fulfillments');
        $isPg = $this->db()->getDriverName() === 'pgsql';

        $this->schema()->table($returns, static function (Blueprint $table): void {
            $table->string('disposition', 32)->nullable();
            $table->string('reason_code', 32)->nullable();
        });

        if ($isPg) {
            $this->check($returns, 'disposition_valid', 'disposition is null or disposition in ('.self::DISPOSITIONS.')');
            $this->check($returns, 'reason_code_valid', 'reason_code is null or reason_code in ('.self::REASONS.')');
        }

        $this->schema()->table($returnLines, static function (Blueprint $table): void {
            $table->integer('written_off_quantity')->default(0);
            $table->string('disposition', 32)->nullable();
            $table->string('reason_code', 32)->nullable();
        });

        if ($isPg) {
            $this->check($returnLines, 'written_off_non_negative', 'written_off_quantity >= 0');
            $this->check($returnLines, 'disposition_within_quantity', 'restocked_quantity + written_off_quantity <= quantity');
            $this->check($returnLines, 'disposition_valid', 'disposition is null or disposition in ('.self::DISPOSITIONS.')');
            $this->check($returnLines, 'reason_code_valid', 'reason_code is null or reason_code in ('.self::REASONS.')');
        }

        $exchanges = $this->create('exchanges', function (Blueprint $table) use ($orders, $returns, $fulfillments): void {
            $this->uuidPrimary($table);
            $table->uuid('order_id');
            $table->uuid('return_id');
            $table->uuid('fulfillment_id')->nullable();
            $table->string('status', 16)->default('requested');
            $table->string('settlement', 16);
            $table->string('reason_code', 32)->nullable();
            $table->string('note', 191)->nullable();
            $table->char('currency', 3);
            $table->bigInteger('return_total_minor');
            $table->bigInteger('outbound_subtotal_minor');
            $table->bigInteger('outbound_tax_total_minor');
            $table->bigInteger('outbound_total_minor');
            $table->bigInteger('net_delta_minor');
            $table->bigInteger('amount_due_minor');
            $table->bigInteger('refund_due_minor');
            $table->timestampTz('completed_at', 6)->nullable();
            $table->timestampTz('cancelled_at', 6)->nullable();
            $this->timestampsTz($table);
            $this->foreign($table, 'order_id')->references('id')->on($orders);
            $this->foreign($table, 'return_id')->references('id')->on($returns);
            $this->foreign($table, 'fulfillment_id')->references('id')->on($fulfillments);
            $this->unique($table, 'return_id');
            $this->index($table, ['order_id', 'created_at']);
            $this->index($table, 'fulfillment_id');
        });

        if ($isPg) {
            $this->check($exchanges, 'status_valid', "status in ('requested', 'completed', 'cancelled')");
            $this->check($exchanges, 'settlement_valid', "settlement in ('even', 'payment_due', 'refund_due')");
            $this->check($exchanges, 'reason_code_valid', 'reason_code is null or reason_code in ('.self::REASONS.')');
            $this->check($exchanges, 'currency_valid', "currency ~ '^[A-Z]{3}$'");
            $this->check($exchanges, 'amounts_non_negative', 'return_total_minor >= 0 and outbound_subtotal_minor >= 0 and outbound_tax_total_minor >= 0 and outbound_total_minor >= 0 and amount_due_minor >= 0 and refund_due_minor >= 0');
            $this->check($exchanges, 'delta_consistent', 'net_delta_minor = outbound_total_minor - return_total_minor');
            $this->check($exchanges, 'completed_matches_status', "(status = 'completed') = (completed_at is not null and fulfillment_id is not null)");
            $this->check($exchanges, 'cancelled_matches_status', "(status = 'cancelled') = (cancelled_at is not null)");
        }

        $exchangeInboundLines = $this->create('exchange_inbound_lines', function (Blueprint $table) use ($exchanges, $orderLines): void {
            $table->uuid('exchange_id');
            $table->uuid('order_line_id');
            $this->ref($table, 'sellable');
            $table->string('sku', 191);
            $table->text('name');
            $table->integer('quantity');
            $table->char('currency', 3);
            $table->bigInteger('credit_total_minor');
            $table->primary(['exchange_id', 'order_line_id']);
            $this->foreign($table, 'exchange_id')->references('id')->on($exchanges)->cascadeOnDelete();
            $this->foreign($table, 'order_line_id')->references('id')->on($orderLines);
        });

        if ($isPg) {
            $this->check($exchangeInboundLines, 'quantity_positive', 'quantity >= 1');
            $this->check($exchangeInboundLines, 'credit_non_negative', 'credit_total_minor >= 0');
        }

        $exchangeOutboundLines = $this->create('exchange_outbound_lines', function (Blueprint $table) use ($exchanges): void {
            $this->uuidPrimary($table);
            $table->uuid('exchange_id');
            $table->integer('position');
            $this->ref($table, 'sellable');
            $table->string('sku', 191);
            $table->text('name');
            $table->string('kind', 16);
            $table->boolean('requires_shipping');
            $table->integer('weight_grams')->nullable();
            $table->integer('quantity');
            $table->char('currency', 3);
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('tax_total_minor');
            $table->bigInteger('total_minor');
            $this->foreign($table, 'exchange_id')->references('id')->on($exchanges)->cascadeOnDelete();
            $this->index($table, ['exchange_id', 'position']);
        });

        if ($isPg) {
            $this->check($exchangeOutboundLines, 'quantity_positive', 'quantity >= 1');
            $this->check($exchangeOutboundLines, 'kind_valid', "kind in ('physical', 'digital', 'service')");
            $this->check($exchangeOutboundLines, 'amounts_non_negative', 'unit_price_minor >= 0 and subtotal_minor >= 0 and tax_total_minor >= 0 and total_minor >= 0');
        }

        $claims = $this->create('claims', function (Blueprint $table) use ($orders, $fulfillments): void {
            $this->uuidPrimary($table);
            $table->uuid('order_id');
            $table->uuid('fulfillment_id')->nullable();
            $table->string('kind', 16);
            $table->string('status', 16)->default('created');
            $table->string('reason_code', 32)->nullable();
            $table->string('note', 191)->nullable();
            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('tax_total_minor')->default(0);
            $table->bigInteger('invoice_total_minor')->default(0);
            $table->bigInteger('amount_due_minor')->default(0);
            $table->timestampTz('fulfilled_at', 6)->nullable();
            $table->timestampTz('cancelled_at', 6)->nullable();
            $this->timestampsTz($table);
            $this->foreign($table, 'order_id')->references('id')->on($orders);
            $this->foreign($table, 'fulfillment_id')->references('id')->on($fulfillments);
            $this->index($table, ['order_id', 'created_at']);
            $this->index($table, 'fulfillment_id');
        });

        if ($isPg) {
            $this->check($claims, 'kind_valid', "kind in ('replacement', 'reshipment')");
            $this->check($claims, 'status_valid', "status in ('created', 'fulfilled', 'cancelled')");
            $this->check($claims, 'reason_code_valid', 'reason_code is null or reason_code in ('.self::REASONS.')');
            $this->check($claims, 'zero_invoice', 'subtotal_minor = 0 and tax_total_minor = 0 and invoice_total_minor = 0 and amount_due_minor = 0');
            $this->check($claims, 'fulfilled_matches_status', "(status = 'fulfilled') = (fulfilled_at is not null and fulfillment_id is not null)");
            $this->check($claims, 'cancelled_matches_status', "(status = 'cancelled') = (cancelled_at is not null)");
        }

        $claimLines = $this->create('claim_lines', function (Blueprint $table) use ($claims, $orderLines): void {
            $this->uuidPrimary($table);
            $table->uuid('claim_id');
            $table->uuid('source_order_line_id')->nullable();
            $table->integer('position');
            $this->ref($table, 'sellable');
            $table->string('sku', 191);
            $table->text('name');
            $table->string('kind', 16);
            $table->boolean('requires_shipping');
            $table->integer('weight_grams')->nullable();
            $table->integer('quantity');
            $table->char('currency', 3);
            $table->bigInteger('unit_price_minor')->default(0);
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('tax_total_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $this->foreign($table, 'claim_id')->references('id')->on($claims)->cascadeOnDelete();
            $this->foreign($table, 'source_order_line_id')->references('id')->on($orderLines);
            $this->index($table, ['claim_id', 'position']);
        });

        if ($isPg) {
            $this->check($claimLines, 'quantity_positive', 'quantity >= 1');
            $this->check($claimLines, 'kind_valid', "kind in ('physical', 'digital', 'service')");
            $this->check($claimLines, 'zero_line_amounts', 'unit_price_minor = 0 and subtotal_minor = 0 and tax_total_minor = 0 and total_minor = 0');
        }
    }

    public function down(): void
    {
        $returns = $this->table('returns');
        $returnLines = $this->table('return_lines');
        $isPg = $this->db()->getDriverName() === 'pgsql';

        $this->drop('claim_lines');
        $this->drop('claims');
        $this->drop('exchange_outbound_lines');
        $this->drop('exchange_inbound_lines');
        $this->drop('exchanges');

        if ($isPg) {
            foreach (['written_off_non_negative', 'disposition_within_quantity', 'disposition_valid', 'reason_code_valid'] as $name) {
                $constraint = ConstraintNames::check($returnLines, $name);
                $this->db()->statement("alter table \"{$returnLines}\" drop constraint if exists \"{$constraint}\"");
            }

            foreach (['disposition_valid', 'reason_code_valid'] as $name) {
                $constraint = ConstraintNames::check($returns, $name);
                $this->db()->statement("alter table \"{$returns}\" drop constraint if exists \"{$constraint}\"");
            }
        }

        $this->schema()->table($returnLines, static function (Blueprint $table): void {
            $table->dropColumn(['written_off_quantity', 'disposition', 'reason_code']);
        });

        $this->schema()->table($returns, static function (Blueprint $table): void {
            $table->dropColumn(['disposition', 'reason_code']);
        });
    }
};
