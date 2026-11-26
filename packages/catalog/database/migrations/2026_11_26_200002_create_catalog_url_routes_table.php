<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Data\Compiler\ConstraintNames;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $routes = $this->create('url_routes', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            // No foreign key: channels belong to the core package.
            $table->uuid('channel_id');
            $table->string('locale', 16);
            $table->string('slug', 255);
            $table->string('entity_type', 64);
            $table->uuid('entity_id');
            $table->boolean('is_canonical')->default(true);
            $table->jsonb('seo_meta')->default('{}');
            $this->timestampsTz($table);
            $this->unique($table, ['channel_id', 'locale', 'slug']);
            $this->index($table, ['entity_type', 'entity_id']);
            $this->index($table, ['channel_id', 'locale', 'entity_type', 'entity_id']);
        });

        $this->check($routes, 'seo_meta_object', "jsonb_typeof(seo_meta) = 'object'");

        $canonicalIndex = ConstraintNames::shorten("{$routes}_canonical_unique");
        $this->schema()->getConnection()->statement(
            "create unique index {$canonicalIndex} on {$routes} (channel_id, locale, entity_type, entity_id) where is_canonical = true",
        );
    }

    public function down(): void
    {
        $this->drop('url_routes');
    }
};
