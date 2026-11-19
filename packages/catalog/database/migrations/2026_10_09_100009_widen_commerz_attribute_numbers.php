<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $table = $this->table('attribute_index');
        $this->db()->statement("alter table \"{$table}\" alter column num type numeric");
    }

    public function down(): void
    {
        $table = $this->table('attribute_index');
        // Refuse a narrowing rollback that could round a contribution's numeric facts.
        if ($this->db()->table($table)->whereRaw('num <> round(num, 12)')->exists()) {
            throw new LogicException('Cannot narrow attribute numbers to numeric(38,12) without losing precision.');
        }

        $this->db()->statement("alter table \"{$table}\" alter column num type numeric(38,12)");
    }
};
