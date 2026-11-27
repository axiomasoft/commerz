<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $isPg = $this->db()->getDriverName() === 'pgsql';

        $units = $this->create('b2b_business_units', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('code', 64);
            $table->string('name', 191);
            $table->uuid('parent_id')->nullable();
            $table->uuid('root_id');
            $table->string('materialized_path', 1024);
            $table->integer('depth')->default(0);
            $table->string('status', 16)->default('active');
            $table->char('default_currency', 3);
            $table->string('tax_id', 64)->nullable();
            $table->string('default_payment_terms_code', 64)->nullable();
            $table->jsonb('billing_address')->nullable();
            $table->jsonb('shipping_address')->nullable();
            $this->timestampsTz($table);
            $this->unique($table, 'code');
            $this->index($table, 'parent_id');
            $this->index($table, 'root_id');
            $this->index($table, 'materialized_path');
        });

        // A self-reference needs the primary key. Blueprint adds a table's own foreign keys before that key.
        $this->schema()->table($units, function (Blueprint $table) use ($units): void {
            $this->foreign($table, 'parent_id')->references('id')->on($units)->restrictOnDelete();
        });

        if ($isPg) {
            $this->check($units, 'depth_non_negative', 'depth >= 0');
            $this->check($units, 'status_valid', "status in ('active', 'suspended', 'archived')");
        }

        $associates = $this->create('b2b_associates', function (Blueprint $table) use ($units): void {
            $this->uuidPrimary($table);
            $table->uuid('business_unit_id');
            $this->ref($table, 'customer');
            $table->string('role', 24);
            $table->char('spending_currency', 3)->nullable();
            $table->bigInteger('per_order_limit_minor')->nullable();
            $table->bigInteger('period_limit_minor')->nullable();
            $this->timestampsTz($table);
            $this->unique($table, ['business_unit_id', 'customer_ref']);
            $this->index($table, 'customer_ref');
            $this->foreign($table, 'business_unit_id')->references('id')->on($units)->cascadeOnDelete();
        });

        if ($isPg) {
            $this->check($associates, 'role_valid', "role in ('admin', 'approver', 'buyer', 'viewer')");
            $this->check($associates, 'per_order_limit_non_negative', 'per_order_limit_minor is null or per_order_limit_minor >= 0');
            $this->check($associates, 'period_limit_non_negative', 'period_limit_minor is null or period_limit_minor >= 0');
        }

        $paymentTerms = $this->create('b2b_payment_terms', function (Blueprint $table) use ($units): void {
            $this->uuidPrimary($table);
            $table->string('code', 64);
            $table->uuid('business_unit_id')->nullable();
            $table->string('type', 32);
            $table->integer('net_days');
            $table->jsonb('milestones')->default('[]');
            $this->timestampsTz($table);
            $this->unique($table, 'code');
            $this->index($table, 'business_unit_id');
            $this->foreign($table, 'business_unit_id')->references('id')->on($units)->cascadeOnDelete();
        });

        if ($isPg) {
            $this->check($paymentTerms, 'type_valid', "type in ('net_15', 'net_30', 'net_60', 'deposit_schedule')");
            $this->check($paymentTerms, 'net_days_non_negative', 'net_days >= 0');
        }

        $creditAccounts = $this->create('b2b_credit_accounts', function (Blueprint $table) use ($units): void {
            $this->uuidPrimary($table);
            $table->uuid('business_unit_id');
            $table->char('currency', 3);
            $table->string('status', 16)->default('active');
            $table->bigInteger('credit_limit_minor')->default(0);
            $table->bigInteger('used_credit_minor')->default(0);
            $table->bigInteger('reserved_credit_minor')->default(0);
            $table->bigInteger('version')->default(0);
            $this->timestampsTz($table);
            $this->unique($table, ['business_unit_id', 'currency']);
            $this->foreign($table, 'business_unit_id')->references('id')->on($units)->restrictOnDelete();
        });

        if ($isPg) {
            $this->check($creditAccounts, 'status_valid', "status in ('active', 'frozen')");
            $this->check($creditAccounts, 'limit_non_negative', 'credit_limit_minor >= 0');
            $this->check($creditAccounts, 'used_non_negative', 'used_credit_minor >= 0');
            $this->check($creditAccounts, 'reserved_non_negative', 'reserved_credit_minor >= 0');
            $this->check($creditAccounts, 'inv_b2b_credit_001', 'used_credit_minor + reserved_credit_minor <= credit_limit_minor');
            $this->check($creditAccounts, 'version_non_negative', 'version >= 0');
        }

        $creditHolds = $this->create('b2b_credit_holds', function (Blueprint $table) use ($creditAccounts): void {
            $this->uuidPrimary($table);
            $table->uuid('credit_account_id');
            $table->string('reference', 191);
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->string('state', 16)->default('reserved');
            $table->timestampTz('due_at', 6)->nullable();
            $this->timestampsTz($table);
            $this->unique($table, 'reference');
            $this->index($table, ['credit_account_id', 'state']);
            $this->foreign($table, 'credit_account_id')->references('id')->on($creditAccounts)->restrictOnDelete();
        });

        if ($isPg) {
            $this->check($creditHolds, 'amount_positive', 'amount_minor > 0');
            $this->check($creditHolds, 'state_valid', "state in ('reserved', 'captured', 'released')");
        }

        $approvalRules = $this->create('b2b_approval_rules', function (Blueprint $table) use ($units): void {
            $this->uuidPrimary($table);
            $table->uuid('business_unit_id');
            $table->string('name', 191);
            $table->char('currency', 3);
            $table->bigInteger('threshold_minor');
            $table->string('approver_role', 24);
            $table->integer('required_approvals')->default(1);
            $table->boolean('applies_to_descendants')->default(true);
            $table->boolean('active')->default(true);
            $this->timestampsTz($table);
            $this->index($table, ['business_unit_id', 'active']);
            $this->foreign($table, 'business_unit_id')->references('id')->on($units)->cascadeOnDelete();
        });

        if ($isPg) {
            $this->check($approvalRules, 'threshold_non_negative', 'threshold_minor >= 0');
            $this->check($approvalRules, 'approver_role_valid', "approver_role in ('approver', 'admin')");
            $this->check($approvalRules, 'required_approvals_positive', 'required_approvals >= 1');
        }

        $approvalFlows = $this->create('b2b_approval_flows', function (Blueprint $table) use ($units): void {
            $this->uuidPrimary($table);
            $table->uuid('business_unit_id');
            $this->ref($table, 'requested_by');
            $this->ref($table, 'subject');
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->string('status', 16)->default('pending');
            $table->string('required_role', 24);
            $table->integer('required_approvals')->default(1);
            $table->jsonb('reasons')->default('[]');
            $table->jsonb('decisions')->default('[]');
            $table->timestampTz('resolved_at', 6)->nullable();
            $this->timestampsTz($table);
            $this->index($table, ['business_unit_id', 'status']);
            $this->index($table, 'subject_ref');
            $this->foreign($table, 'business_unit_id')->references('id')->on($units)->restrictOnDelete();
        });

        if ($isPg) {
            $this->check($approvalFlows, 'amount_non_negative', 'amount_minor >= 0');
            $this->check($approvalFlows, 'status_valid', "status in ('pending', 'approved', 'rejected', 'canceled')");
            $this->check($approvalFlows, 'required_role_valid', "required_role in ('approver', 'admin')");
            $this->check($approvalFlows, 'required_approvals_positive', 'required_approvals >= 1');
        }

        $quoteRequests = $this->create('b2b_quote_requests', function (Blueprint $table) use ($units): void {
            $this->uuidPrimary($table);
            $table->string('number', 64);
            $table->uuid('business_unit_id');
            $this->ref($table, 'buyer');
            $table->uuid('channel_id');
            $table->char('currency', 3);
            $table->string('locale', 32);
            $table->jsonb('customer_groups')->default('[]');
            $table->string('source_cart_id', 64)->nullable();
            $table->jsonb('lines')->default('[]');
            $table->jsonb('requested_payment_terms')->nullable();
            $table->string('purchase_order_number', 128)->nullable();
            $table->text('buyer_note')->nullable();
            $table->jsonb('shipping_address')->nullable();
            $table->jsonb('billing_address')->nullable();
            $table->string('delivery_method', 64)->nullable();
            $table->jsonb('codes')->default('[]');
            $table->string('status', 32)->default('submitted');
            $table->integer('revision')->default(1);
            $this->timestampsTz($table);
            $this->unique($table, 'number');
            $this->index($table, ['business_unit_id', 'status']);
            $this->index($table, 'buyer_ref');
            $this->foreign($table, 'business_unit_id')->references('id')->on($units)->restrictOnDelete();
        });

        if ($isPg) {
            $this->check($quoteRequests, 'status_valid', "status in ('submitted', 'in_review', 'quoted', 'renegotiation_requested', 'converted', 'rejected', 'canceled')");
            $this->check($quoteRequests, 'revision_positive', 'revision >= 1');
        }

        $stagedQuotes = $this->create('b2b_staged_quotes', function (Blueprint $table) use ($quoteRequests, $units): void {
            $this->uuidPrimary($table);
            $table->uuid('quote_request_id');
            $table->uuid('business_unit_id');
            $table->integer('revision')->default(1);
            $table->string('status', 24)->default('draft');
            $this->ref($table, 'seller_actor');
            $table->char('currency', 3);
            $table->jsonb('line_overrides')->default('[]');
            $table->jsonb('priced_lines')->default('[]');
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('discount_total_minor')->default(0);
            $table->bigInteger('delivery_total_minor')->default(0);
            $table->bigInteger('tax_total_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->jsonb('payment_terms')->nullable();
            $table->timestampTz('valid_until', 6);
            $table->text('seller_note')->nullable();
            $this->timestampsTz($table);
            $this->index($table, ['quote_request_id', 'revision']);
            $this->index($table, ['business_unit_id', 'status']);
            $this->foreign($table, 'quote_request_id')->references('id')->on($quoteRequests)->restrictOnDelete();
            $this->foreign($table, 'business_unit_id')->references('id')->on($units)->restrictOnDelete();
        });

        if ($isPg) {
            $this->check($stagedQuotes, 'status_valid', "status in ('draft', 'published', 'superseded', 'abandoned')");
            $this->check($stagedQuotes, 'revision_positive', 'revision >= 1');
            $this->check($stagedQuotes, 'total_non_negative', 'total_minor >= 0');
        }

        $negotiatedQuotes = $this->create('b2b_negotiated_quotes', function (Blueprint $table) use ($quoteRequests, $stagedQuotes, $units, $approvalFlows): void {
            $this->uuidPrimary($table);
            $table->uuid('quote_request_id');
            $table->uuid('staged_quote_id');
            $table->uuid('business_unit_id');
            $this->ref($table, 'buyer');
            $table->integer('revision')->default(1);
            $table->string('status', 32)->default('sent');
            $table->uuid('channel_id');
            $table->char('currency', 3);
            $table->string('locale', 32);
            $table->jsonb('customer_groups')->default('[]');
            $table->jsonb('locked_lines')->default('[]');
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('discount_total_minor')->default(0);
            $table->bigInteger('delivery_total_minor')->default(0);
            $table->bigInteger('tax_total_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->jsonb('payment_terms')->nullable();
            $table->jsonb('shipping_address')->nullable();
            $table->jsonb('billing_address')->nullable();
            $table->string('delivery_method', 64)->nullable();
            $table->jsonb('codes')->default('[]');
            $table->timestampTz('valid_until', 6);
            $table->text('seller_note')->nullable();
            $table->text('renegotiation_comment')->nullable();
            $table->uuid('approval_flow_id')->nullable();
            $table->string('converted_order_ref', 191)->nullable();
            $table->timestampTz('sent_at', 6);
            $table->timestampTz('converted_at', 6)->nullable();
            $this->timestampsTz($table);
            $this->index($table, ['quote_request_id', 'revision']);
            $this->index($table, ['business_unit_id', 'status']);
            $this->foreign($table, 'quote_request_id')->references('id')->on($quoteRequests)->restrictOnDelete();
            $this->foreign($table, 'staged_quote_id')->references('id')->on($stagedQuotes)->restrictOnDelete();
            $this->foreign($table, 'business_unit_id')->references('id')->on($units)->restrictOnDelete();
            $this->foreign($table, 'approval_flow_id')->references('id')->on($approvalFlows)->restrictOnDelete();
        });

        if ($isPg) {
            $this->check($negotiatedQuotes, 'status_valid', "status in ('sent', 'renegotiation_requested', 'converted', 'expired', 'rejected', 'superseded')");
            $this->check($negotiatedQuotes, 'revision_positive', 'revision >= 1');
            $this->check($negotiatedQuotes, 'total_non_negative', 'total_minor >= 0');
        }
    }

    public function down(): void
    {
        $this->drop('b2b_negotiated_quotes');
        $this->drop('b2b_staged_quotes');
        $this->drop('b2b_quote_requests');
        $this->drop('b2b_approval_flows');
        $this->drop('b2b_approval_rules');
        $this->drop('b2b_credit_holds');
        $this->drop('b2b_credit_accounts');
        $this->drop('b2b_payment_terms');
        $this->drop('b2b_associates');
        $this->drop('b2b_business_units');
    }
};
