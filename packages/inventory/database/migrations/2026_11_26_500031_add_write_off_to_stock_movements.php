<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Packman\Schema\Data\Compiler\ConstraintNames;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $movements = $this->table('stock_movements');

        if ($this->db()->getDriverName() === 'pgsql') {
            $kindCheck = ConstraintNames::check($movements, 'kind_valid');
            $this->db()->statement("alter table \"{$movements}\" drop constraint if exists \"{$kindCheck}\"");

            $this->check(
                $movements,
                'kind_valid',
                "kind in ('receipt', 'adjustment', 'count', 'sale', 'transfer_out', 'transfer_in', 'return', 'write_off')",
            );
            $this->check(
                $movements,
                'write_off_negative',
                "kind <> 'write_off' or delta < 0",
            );
        }
    }

    public function down(): void
    {
        $movements = $this->table('stock_movements');

        if ($this->db()->getDriverName() === 'pgsql') {
            $this->db()->table($movements)->where('kind', 'write_off')->delete();

            $writeOffCheck = ConstraintNames::check($movements, 'write_off_negative');
            $kindCheck = ConstraintNames::check($movements, 'kind_valid');

            $this->db()->statement("alter table \"{$movements}\" drop constraint if exists \"{$writeOffCheck}\"");
            $this->db()->statement("alter table \"{$movements}\" drop constraint if exists \"{$kindCheck}\"");

            $this->check(
                $movements,
                'kind_valid',
                "kind in ('receipt', 'adjustment', 'count', 'sale', 'transfer_out', 'transfer_in', 'return')",
            );
        }
    }
};
