<?php

declare(strict_types=1);

namespace Commerz\Links\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Immutable view of a persisted cross-module link row in `cmz_entity_links`.
 */
#[Api(since: '0.1')]
final readonly class EntityLink
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $id,
        public string $linkCode,
        public string $leftType,
        public string $leftId,
        public string $rightType,
        public string $rightId,
        public int $position,
        public array $extra,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
