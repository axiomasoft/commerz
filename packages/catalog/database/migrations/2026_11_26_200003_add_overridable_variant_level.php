<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Packman\Schema\Data\Compiler\ConstraintNames;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $table = $this->table('product_type_attributes');

        if ($this->db()->getDriverName() === 'pgsql') {
            $constraint = ConstraintNames::check($table, 'level_valid');
            $this->db()->statement("alter table \"{$table}\" drop constraint \"{$constraint}\"");
            $this->db()->statement("alter table \"{$table}\" alter column level type varchar(32)");
            $this->check($table, 'level_valid', "level in ('product', 'variant', 'overridable_variant')");
        }
    }

    public function down(): void
    {
        $table = $this->table('product_type_attributes');

        if ($this->db()->table($table)->where('level', 'overridable_variant')->exists()) {
            throw new LogicException('Cannot remove overridable_variant level while product type slots use it.');
        }

        if ($this->db()->getDriverName() === 'pgsql') {
            $constraint = ConstraintNames::check($table, 'level_valid');
            $this->db()->statement("alter table \"{$table}\" drop constraint \"{$constraint}\"");
            $this->db()->statement("alter table \"{$table}\" alter column level type varchar(16)");
            $this->check($table, 'level_valid', "level in ('product', 'variant')");
        }
    }
};
