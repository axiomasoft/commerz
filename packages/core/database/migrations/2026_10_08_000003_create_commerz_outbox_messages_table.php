<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Data\Compiler\ConstraintNames;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        $table = $this->create('outbox_messages', function (Blueprint $table): void {
            $this->identityPrimary($table);
            $table->uuid('event_id');
            $table->string('type', 191);
            $table->smallInteger('version');
            $table->string('aggregate_ref', 191);
            $table->jsonb('payload');
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('available_at', 6)->useCurrent();
            $table->integer('attempts')->default(0);
            $table->timestampTz('delivered_at', 6)->nullable();
            $table->text('last_error')->nullable();
            $this->unique($table, 'event_id');
        });

        $this->check($table, 'version_positive', 'version > 0');
        $this->check($table, 'attempts_not_negative', 'attempts >= 0');

        // The relay scans only pending rows; pruning scans only delivered ones.
        $pending = ConstraintNames::index($table, ['available_at', 'id']);
        $delivered = ConstraintNames::index($table, 'delivered_at');
        $this->db()->statement("create index \"{$pending}\" on \"{$table}\" (available_at, id) where delivered_at is null");
        $this->db()->statement("create index \"{$delivered}\" on \"{$table}\" (delivered_at) where delivered_at is not null");
    }

    public function down(): void
    {
        $this->drop('outbox_messages');
    }
};
