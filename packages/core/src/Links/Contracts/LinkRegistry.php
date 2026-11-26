<?php

declare(strict_types=1);

namespace Commerz\Links\Contracts;

use Commerz\Links\Data\EntityLink;
use Commerz\Links\Data\LinkDefinition;
use Packman\Attributes\Api;

/**
 * Registry of cross-module entity link definitions and FK-free persistence operations
 * backed by `cmz_entity_links`.
 */
#[Api(since: '0.1')]
interface LinkRegistry
{
    public function define(LinkDefinition $definition): void;

    public function definition(string $linkCode): LinkDefinition;

    public function hasDefinition(string $linkCode): bool;

    /** @return array<string, LinkDefinition> */
    public function definitions(): array;

    /**
     * Links `$leftId` to `$rightId` under `$linkCode`, enforcing cardinality rules.
     * Re-linking an already linked pair updates its position and extra payload idempotently.
     *
     * @param  array<string, mixed>  $extra
     */
    public function link(
        string $linkCode,
        string $leftId,
        string $rightId,
        int $position = 0,
        array $extra = [],
    ): EntityLink;

    /** Removes a single link between `$leftId` and `$rightId`. Returns true when a link was deleted. */
    public function unlink(string $linkCode, string $leftId, string $rightId): bool;

    /**
     * Applies cascade unlink policies for `$entityType:$entityId` across all matching definitions
     * (or a single `$linkCode`). Returns the number of deleted link rows.
     */
    public function unlinkAllFor(string $entityType, string $entityId, ?string $linkCode = null): int;

    /**
     * Batch-loads forward links for many left entity IDs in a single query, ordered by position then id.
     *
     * @param  list<string>  $leftIds
     * @return array<string, list<EntityLink>>
     */
    public function linksForMany(string $linkCode, array $leftIds): array;

    /**
     * Batch-loads inverse links for many right entity IDs in a single query, ordered by position then id.
     *
     * @param  list<string>  $rightIds
     * @return array<string, list<EntityLink>>
     */
    public function inverseLinksForMany(string $linkCode, array $rightIds): array;
}
