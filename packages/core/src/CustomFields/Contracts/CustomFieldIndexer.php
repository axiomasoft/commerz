<?php

declare(strict_types=1);

namespace Commerz\CustomFields\Contracts;

use Commerz\CustomFields\Data\PreparedCustomFields;
use Commerz\Metadata\MetadataBag;
use Packman\Attributes\Api;

/**
 * Maintains the `cmz_custom_field_index` projection for filterable custom fields on root aggregates.
 */
#[Api(since: '0.1')]
interface CustomFieldIndexer
{
    /**
     * Validates `$metadata` against `CustomFieldRegistry`, replaces all index rows for `($targetAggregate, $aggregateId)`,
     * and returns the normalized `MetadataBag`.
     */
    public function sync(
        string $targetAggregate,
        string $aggregateId,
        MetadataBag $metadata,
        bool $creating = false,
    ): MetadataBag;

    /**
     * Applies pre-validated `PreparedCustomFields` projection rows for `$aggregateId`.
     */
    public function apply(string $aggregateId, PreparedCustomFields $prepared): void;

    /**
     * Deletes all projection rows belonging to `($targetAggregate, $aggregateId)`.
     */
    public function deleteAggregate(string $targetAggregate, string $aggregateId): void;

    /**
     * Deletes all projection rows for a specific custom field definition across `$targetAggregate`.
     */
    public function deleteField(string $targetAggregate, string $namespace, string $key): void;
}
