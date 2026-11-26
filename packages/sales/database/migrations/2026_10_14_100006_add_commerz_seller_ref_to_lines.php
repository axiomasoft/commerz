<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $this->schema()->table($this->table('cart_lines'), function (Blueprint $table): void {
            $this->ref($table, 'seller', nullable: true);
        });

        $this->schema()->table($this->table('order_lines'), function (Blueprint $table): void {
            $this->ref($table, 'seller', nullable: true);
        });
    }

    public function down(): void
    {
        $this->schema()->table($this->table('order_lines'), static function (Blueprint $table): void {
            $table->dropColumn('seller_ref');
        });

        $this->schema()->table($this->table('cart_lines'), static function (Blueprint $table): void {
            $table->dropColumn('seller_ref');
        });
    }
};
