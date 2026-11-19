<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Commerz\Schema\Internal\LegacyFingerprint;
use Commerz\Schema\TableNames;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Schema\Data\Compiler\SchemaFingerprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $this->changeOwner(null, TableNames::OWNER);
    }

    public function down(): void
    {
        $this->changeOwner(TableNames::OWNER, null);
    }

    private function changeOwner(?string $from, ?string $to): void
    {
        $db = $this->db();
        $prefix = $this->tables()->prefix();
        $fingerprint = $db->table($this->table('schema_state'))->where('key', SchemaFingerprint::KEY);
        $value = $fingerprint->lockForUpdate()->value('value');
        $document = is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : null;

        $expected = SchemaFingerprint::document($prefix, $from);
        $target = SchemaFingerprint::document($prefix, $to);
        if (is_array($document) && $this->matches($document, $target)) {
            return;
        }

        if (! is_array($document) || ! $this->matches($document, $expected) || ! LegacyFingerprint::owns($db, $prefix)) {
            throw new InvalidConfiguration(['commerz.table_prefix: expected a Commerz installation with a valid schema fingerprint']);
        }

        unset($document['owner']);
        $fingerprint->update([
            'value' => json_encode(array_replace($document, $target), JSON_THROW_ON_ERROR),
            'updated_at' => $db->raw('CURRENT_TIMESTAMP'),
        ]);
    }

    /** @param array<array-key, mixed> $document
     * @param  array<string, int|string>  $expected
     */
    private function matches(array $document, array $expected): bool
    {
        if (($document['owner'] ?? null) !== ($expected['owner'] ?? null)) {
            return false;
        }

        return array_all($expected, static fn (int|string $value, string $key): bool => ($document[$key] ?? null) === $value);
    }
};
