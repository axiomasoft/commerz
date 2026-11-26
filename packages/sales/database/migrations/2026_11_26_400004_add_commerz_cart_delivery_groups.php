<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Data\Compiler\ConstraintNames;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $carts = $this->table('carts');

        $this->schema()->table($carts, static function (Blueprint $table): void {
            $table->jsonb('pickup_point')->nullable();
            $table->jsonb('delivery_groups')->default('[]');
        });

        $this->check($carts, 'pickup_point_object', "pickup_point is null or jsonb_typeof(pickup_point) = 'object'");
        $this->check($carts, 'delivery_groups_array', "jsonb_typeof(delivery_groups) = 'array'");
    }

    public function down(): void
    {
        $carts = $this->table('carts');

        foreach (['pickup_point_object', 'delivery_groups_array'] as $check) {
            $name = ConstraintNames::check($carts, $check);
            $this->db()->statement("alter table \"{$carts}\" drop constraint if exists \"{$name}\"");
        }

        $this->schema()->table($carts, static function (Blueprint $table): void {
            $table->dropColumn(['pickup_point', 'delivery_groups']);
        });
    }
};
