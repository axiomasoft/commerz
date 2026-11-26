<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Data\Compiler\ConstraintNames;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $index = $this->create('custom_field_index', function (Blueprint $table): void {
            $this->identityPrimary($table);
            $table->string('target_aggregate', 64);
            $table->string('aggregate_id', 191);
            $table->string('namespace', 64);
            $table->string('field_key', 64);
            $table->string('visibility', 32);
            $table->decimal('num', 38, 12)->nullable();
            $table->boolean('flag')->nullable();
            $table->date('day')->nullable();
            $table->string('term', 255)->nullable();
            $table->timestampTz('indexed_at', 6);

            $this->index($table, ['target_aggregate', 'aggregate_id']);
            $this->index($table, ['target_aggregate', 'namespace', 'field_key']);
            $this->index($table, ['target_aggregate', 'visibility', 'namespace', 'field_key', 'term']);
            $this->index($table, ['target_aggregate', 'visibility', 'namespace', 'field_key', 'num']);
            $this->index($table, ['target_aggregate', 'visibility', 'namespace', 'field_key', 'day']);
            $this->index($table, ['target_aggregate', 'visibility', 'namespace', 'field_key', 'flag']);
        });

        $storefrontIndex = ConstraintNames::shorten("{$index}_public_storefront_target_aggregate_namespace_field_key_ix");
        $this->db()->statement(
            "create index \"{$storefrontIndex}\" on \"{$index}\" (target_aggregate, namespace, field_key) where visibility = 'public_storefront'",
        );

        if ($this->db()->getDriverName() === 'pgsql') {
            $this->db()->statement("alter table \"{$index}\" alter column num type numeric");
            $this->check($index, 'visibility_valid', "visibility in ('public_storefront', 'private_admin')");
            $this->check($index, 'one_value', 'num_nonnulls(num, flag, day, term) = 1');
        }
    }

    public function down(): void
    {
        $this->drop('custom_field_index');
    }
};
