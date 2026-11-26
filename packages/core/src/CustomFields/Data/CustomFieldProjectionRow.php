<?php

declare(strict_types=1);

namespace Commerz\CustomFields\Data;

use Commerz\CustomFields\Enums\FieldVisibility;
use Packman\Attributes\Api;

/**
 * Validated custom field projection row ready for `cmz_custom_field_index`.
 */
#[Api(since: '0.1')]
final readonly class CustomFieldProjectionRow
{
    public function __construct(
        public string $namespace,
        public string $key,
        public FieldVisibility $visibility,
        public CustomFieldIndexFact $fact,
    ) {}
}
