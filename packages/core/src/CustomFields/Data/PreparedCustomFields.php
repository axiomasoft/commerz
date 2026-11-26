<?php

declare(strict_types=1);

namespace Commerz\CustomFields\Data;

use Commerz\Metadata\MetadataBag;
use Packman\Attributes\Api;

/**
 * Validated custom field write unit containing the normalized `MetadataBag` and `cmz_custom_field_index` rows.
 */
#[Api(since: '0.1')]
final readonly class PreparedCustomFields
{
    /**
     * @param  list<CustomFieldProjectionRow>  $rows
     */
    public function __construct(
        public string $targetAggregate,
        public MetadataBag $metadata,
        public array $rows,
    ) {}
}
