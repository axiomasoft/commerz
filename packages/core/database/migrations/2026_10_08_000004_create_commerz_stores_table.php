<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        $table = $this->create('stores', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('code', 64);
            $table->string('name', 191);
            $table->char('default_currency', 3);
            $table->string('order_number_prefix', 16)->default('');
            $table->string('status', 16)->default('active');
            $this->timestampsTz($table);
            $this->unique($table, 'code');
        });

        $this->check($table, 'status_valid', "status in ('active', 'archived')");
    }

    public function down(): void
    {
        $this->drop('stores');
    }
};
