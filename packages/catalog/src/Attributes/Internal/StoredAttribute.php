<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Internal;

use Commerz\Catalog\Attributes\Data\AttributeDefinition;
use Commerz\Catalog\Attributes\Enums\AttributeStatus;

/** An attribute definition row as the writer reads it. */
final readonly class StoredAttribute
{
    public function __construct(
        public string $id,
        public string $type,
        public AttributeStatus $status,
        public bool $filterable,
        public AttributeDefinition $definition,
    ) {}

    /** Whether its values belong in the filter projection (the type must also be supported). */
    public function projected(): bool
    {
        return $this->filterable && $this->status !== AttributeStatus::Retired;
    }
}
