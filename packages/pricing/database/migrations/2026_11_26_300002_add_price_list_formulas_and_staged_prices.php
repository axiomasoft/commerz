<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Data\Compiler\ConstraintNames;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $lists = $this->table('price_lists');

        $this->schema()->table($lists, function (Blueprint $table) use ($lists): void {
            $table->uuid('parent_price_list_id')->nullable();
            $table->jsonb('formula')->nullable();
            $table->jsonb('rounding_rule')->nullable();
            $this->foreign($table, 'parent_price_list_id')->references('id')->on($lists)->restrictOnDelete();
            $this->index($table, 'parent_price_list_id');
        });

        $this->check($lists, 'parent_not_self', 'parent_price_list_id is null or parent_price_list_id <> id');
        $this->check($lists, 'formula_requires_parent', 'formula is null or parent_price_list_id is not null');

        $staged = $this->create('staged_prices', function (Blueprint $table) use ($lists): void {
            $this->uuidPrimary($table);
            $table->string('batch_code', 64)->nullable();
            $table->uuid('price_list_id');
            $this->ref($table, 'sellable');
            $table->string('customer_group', 64)->nullable();
            $table->bigInteger('min_quantity')->default(1);
            $this->money($table, 'amount');
            $this->money($table, 'compare_at', nullable: true);
            $table->timestampTz('valid_from', 6)->nullable();
            $table->timestampTz('valid_until', 6)->nullable();
            $table->string('status', 16)->default('staged');
            $table->uuid('published_price_id')->nullable();
            $table->timestampTz('published_at', 6)->nullable();
            $this->timestampsTz($table);
            $this->foreign($table, 'price_list_id')->references('id')->on($lists)->restrictOnDelete();
            $this->index($table, ['price_list_id', 'status']);
            $this->index($table, ['batch_code', 'status']);
            $this->index($table, ['sellable_ref', 'status']);
        });

        $this->check($staged, 'min_quantity_positive', 'min_quantity >= 1');
        $this->check($staged, 'amount_non_negative', 'amount_minor >= 0');
        $this->check($staged, 'compare_at_above_amount', 'compare_at_minor is null or compare_at_minor > amount_minor');
        $this->check($staged, 'window_ordered', 'valid_from is null or valid_until is null or valid_from < valid_until');
        $this->check($staged, 'status_valid', "status in ('staged', 'published', 'discarded')");
    }

    public function down(): void
    {
        $this->drop('staged_prices');

        $lists = $this->table('price_lists');
        $parentCheck = ConstraintNames::check($lists, 'parent_not_self');
        $formulaCheck = ConstraintNames::check($lists, 'formula_requires_parent');
        $foreign = ConstraintNames::foreign($lists, 'parent_price_list_id');
        $index = ConstraintNames::index($lists, 'parent_price_list_id');

        $this->db()->statement("alter table \"{$lists}\" drop constraint if exists \"{$parentCheck}\"");
        $this->db()->statement("alter table \"{$lists}\" drop constraint if exists \"{$formulaCheck}\"");

        $this->schema()->table($lists, static function (Blueprint $table) use ($foreign, $index): void {
            $table->dropForeign($foreign);
            $table->dropIndex($index);
            $table->dropColumn(['parent_price_list_id', 'formula', 'rounding_rule']);
        });
    }
};
