<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $options = $this->create('options', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('code', 64);
            $table->jsonb('name');
            $this->timestampsTz($table);
            $this->unique($table, 'code');
        });

        $this->create('option_values', function (Blueprint $table) use ($options): void {
            $this->uuidPrimary($table);
            $table->uuid('option_id');
            $table->string('code', 64);
            $table->jsonb('label');
            $table->integer('position')->default(0);
            $this->timestampsTz($table);
            $this->unique($table, ['option_id', 'code']);
            $this->unique($table, ['id', 'option_id']);
            $this->foreign($table, 'option_id')->references('id')->on($options)->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $this->drop('option_values');
        $this->drop('options');
    }
};
