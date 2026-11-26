<?php

declare(strict_types=1);

namespace Commerz\CustomFields\Contracts;

use Commerz\CustomFields\Data\CustomFieldDefinition;
use Commerz\CustomFields\Data\PreparedCustomFields;
use Commerz\CustomFields\Enums\FieldVisibility;
use Commerz\Metadata\MetadataBag;
use Packman\Attributes\Api;

/**
 * Central registry of typed custom field definitions across all Commerz root aggregates.
 */
#[Api(since: '0.1')]
interface CustomFieldRegistry
{
    public function register(CustomFieldDefinition ...$definitions): void;

    public function has(string $targetAggregate, string $namespace, string $key): bool;

    public function get(string $targetAggregate, string $namespace, string $key): CustomFieldDefinition;

    public function find(string $targetAggregate, string $namespace, string $key): ?CustomFieldDefinition;

    /**
     * Lists registered definitions for `$targetAggregate`, optionally narrowed to an exact `FieldVisibility`.
     *
     * @return list<CustomFieldDefinition>
     */
    public function forAggregate(string $targetAggregate, ?FieldVisibility $visibility = null): array;

    /**
     * Lists definitions on `$targetAggregate` accessible to `$audience`
     * (`PublicStorefront` sees only public fields; `PrivateAdmin` sees both public and private fields).
     *
     * @return list<CustomFieldDefinition>
     */
    public function visibleTo(string $targetAggregate, FieldVisibility $audience): array;

    /**
     * Lists filterable definitions on `$targetAggregate`, optionally restricted to `$audience`.
     *
     * @return list<CustomFieldDefinition>
     */
    public function filterableFor(string $targetAggregate, ?FieldVisibility $audience = null): array;

    /**
     * @return list<CustomFieldDefinition>
     */
    public function all(): array;

    /**
     * Validates and normalizes custom field updates (`namespace:key` or `namespace.key` => value), routes values into
     * `MetadataBag::$public` vs `MetadataBag::$private` strictly by each definition's `FieldVisibility`, enforces
     * `isRequired` and `$actorAudience` access boundaries, and prepares `cmz_custom_field_index` rows.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $remove
     */
    public function prepareValues(
        string $targetAggregate,
        array $values,
        ?MetadataBag $current = null,
        array $remove = [],
        bool $creating = true,
        ?FieldVisibility $actorAudience = null,
    ): PreparedCustomFields;

    /**
     * Validates an aggregate's `MetadataBag` against all registered definitions for `$targetAggregate` (rejecting
     * visibility leaks such as a `PrivateAdmin` custom field stored inside `publicMetadata`), normalizes registered
     * values, and computes `cmz_custom_field_index` projection rows.
     */
    public function validateMetadata(
        string $targetAggregate,
        MetadataBag $metadata,
        bool $creating = true,
    ): PreparedCustomFields;

    /**
     * Projects an aggregate's `MetadataBag` for `$audience`, guaranteeing private metadata is never exposed to
     * `FieldVisibility::PublicStorefront`.
     */
    public function projectMetadata(
        string $targetAggregate,
        MetadataBag $metadata,
        FieldVisibility $audience,
    ): MetadataBag;
}
