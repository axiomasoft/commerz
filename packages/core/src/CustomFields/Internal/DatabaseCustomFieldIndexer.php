<?php

declare(strict_types=1);

namespace Commerz\CustomFields\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\CustomFields\Contracts\CustomFieldIndexer;
use Commerz\CustomFields\Contracts\CustomFieldRegistry;
use Commerz\CustomFields\Data\PreparedCustomFields;
use Commerz\Errors\InvalidCommand;
use Commerz\Metadata\MetadataBag;
use Commerz\Schema\TableNames;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use Packman\Attributes\Internal;

/**
 * Synchronizes filterable custom field facts into `cmz_custom_field_index` within the caller's transaction.
 */
#[Internal]
final readonly class DatabaseCustomFieldIndexer implements CustomFieldIndexer
{
    private const int INSERT_CHUNK = 500;

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $options,
        private TableNames $tables,
        private CustomFieldRegistry $registry,
        private Clock $clock,
    ) {}

    #[Override]
    public function sync(
        string $targetAggregate,
        string $aggregateId,
        MetadataBag $metadata,
        bool $creating = false,
    ): MetadataBag {
        $this->assertAggregateId($aggregateId);
        $prepared = $this->registry->validateMetadata($targetAggregate, $metadata, $creating);
        $this->apply($aggregateId, $prepared);

        return $prepared->metadata;
    }

    #[Override]
    public function apply(string $aggregateId, PreparedCustomFields $prepared): void
    {
        $this->assertAggregateId($aggregateId);

        $db = $this->db();
        $indexTable = $this->tables->table('custom_field_index');

        $db->table($indexTable)
            ->where('target_aggregate', $prepared->targetAggregate)
            ->where('aggregate_id', $aggregateId)
            ->delete();

        if ($prepared->rows === []) {
            return;
        }

        $indexedAt = PgTimestamp::now($this->clock);
        $payload = [];

        foreach ($prepared->rows as $row) {
            $payload[] = [
                'target_aggregate' => $prepared->targetAggregate,
                'aggregate_id' => $aggregateId,
                'namespace' => $row->namespace,
                'field_key' => $row->key,
                'visibility' => $row->visibility->value,
                'num' => $row->fact->number,
                'flag' => $row->fact->flag,
                'day' => $row->fact->day,
                'term' => $row->fact->term,
                'indexed_at' => $indexedAt,
            ];
        }

        foreach (array_chunk($payload, self::INSERT_CHUNK) as $chunk) {
            $db->table($indexTable)->insert($chunk);
        }
    }

    #[Override]
    public function deleteAggregate(string $targetAggregate, string $aggregateId): void
    {
        $this->assertAggregateId($aggregateId);

        $this->db()->table($this->tables->table('custom_field_index'))
            ->where('target_aggregate', $targetAggregate)
            ->where('aggregate_id', $aggregateId)
            ->delete();
    }

    #[Override]
    public function deleteField(string $targetAggregate, string $namespace, string $key): void
    {
        $this->db()->table($this->tables->table('custom_field_index'))
            ->where('target_aggregate', $targetAggregate)
            ->where('namespace', $namespace)
            ->where('field_key', $key)
            ->delete();
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->options->connection);
    }

    private function assertAggregateId(string $aggregateId): void
    {
        if (strlen($aggregateId) < 1 || strlen($aggregateId) > 191 || preg_match('/\A[^\p{Cc}]+\z/u', $aggregateId) !== 1) {
            throw new InvalidCommand(
                'aggregateId',
                'must be 1..191 bytes of printable UTF-8',
                'custom_fields.invalid_aggregate_id',
            );
        }
    }
}
