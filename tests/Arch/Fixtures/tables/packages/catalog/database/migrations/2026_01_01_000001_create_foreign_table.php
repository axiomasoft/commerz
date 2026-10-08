<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        // `orders` belongs to the sales package, not to catalog.
        $this->create('orders', function (Blueprint $table): void {
            $this->uuidPrimary($table);
        });
    }

    public function down(): void
    {
        $this->drop('orders');
    }
};
