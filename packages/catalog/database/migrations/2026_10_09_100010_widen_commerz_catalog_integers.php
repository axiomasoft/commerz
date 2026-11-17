<?php

declare(strict_types=1);

use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        $this->change('bigint');
    }

    public function down(): void
    {
        // PostgreSQL refuses an overflowing narrowing cast; no accepted int64 value is truncated.
        $this->change('integer');
    }

    private function change(string $type): void
    {
        foreach (['variants' => ['position', 'weight_grams', 'length_mm', 'width_mm', 'height_mm'], 'categories' => ['position']] as $logical => $columns) {
            $table = $this->table($logical);
            $changes = array_map(static fn (string $column): string => "alter column {$column} type {$type}", $columns);
            $this->db()->statement("alter table \"{$table}\" ".implode(', ', $changes));
        }
    }
};
