<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        $products = $this->table('products');

        $listings = $this->create('listings', function (Blueprint $table) use ($products): void {
            $table->uuid('product_id');
            // No foreign key: channels belong to the core package.
            $table->uuid('channel_id');
            $table->string('status', 16);
            $table->timestampTz('available_from', 6)->nullable();
            $table->timestampTz('available_until', 6)->nullable();
            $this->timestampsTz($table);
            $table->primary(['product_id', 'channel_id']);
            $this->index($table, ['channel_id', 'status']);
            $this->foreign($table, 'product_id')->references('id')->on($products)->restrictOnDelete();
        });

        $this->check($listings, 'status_valid', "status in ('published', 'unpublished')");
        $this->check($listings, 'window', 'available_from is null or available_until is null or available_until > available_from');
    }

    public function down(): void
    {
        $this->drop('listings');
    }
};
