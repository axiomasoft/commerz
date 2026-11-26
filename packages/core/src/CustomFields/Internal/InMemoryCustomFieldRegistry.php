<?php

declare(strict_types=1);

namespace Commerz\CustomFields\Internal;

use Commerz\CustomFields\Contracts\CustomFieldRegistry;
use Commerz\CustomFields\Data\CustomFieldDefinition;
use Commerz\CustomFields\Data\CustomFieldProjectionRow;
use Commerz\CustomFields\Data\PreparedCustomFields;
use Commerz\CustomFields\Enums\FieldVisibility;
use Commerz\CustomFields\Exceptions\CustomFieldNotFound;
use Commerz\CustomFields\Exceptions\CustomFieldVisibilityViolation;
use Commerz\CustomFields\Exceptions\DuplicateCustomField;
use Commerz\CustomFields\Exceptions\InvalidCustomFieldValue;
use Commerz\CustomFields\Exceptions\MissingRequiredCustomFields;
use Commerz\Errors\InvalidCommand;
use Commerz\Metadata\MetadataBag;
use Override;
use Packman\Attributes\Internal;

/**
 * Default in-memory implementation of `CustomFieldRegistry` enforcing deterministic ordering and visibility separation.
 */
#[Internal]
final class InMemoryCustomFieldRegistry implements CustomFieldRegistry
{
    /**
     * @var array<string, array<string, CustomFieldDefinition>> targetAggregate => qualifiedKey => definition
     */
    private array $definitions = [];

    /**
     * @var array<string, array<string, string>> targetAggregate => storageKey (namespace:key) => qualifiedKey
     */
    private array $storageKeyIndex = [];

    #[Override]
    public function register(CustomFieldDefinition ...$definitions): void
    {
        foreach ($definitions as $definition) {
            $aggregate = $definition->targetAggregate;
            $qualifiedKey = $definition->qualifiedKey();
            $storageKey = $definition->storageKey();

            if (isset($this->definitions[$aggregate][$qualifiedKey]) || isset($this->storageKeyIndex[$aggregate][$storageKey])) {
                throw new DuplicateCustomField($aggregate, $definition->namespace, $definition->key);
            }

            $this->definitions[$aggregate][$qualifiedKey] = $definition;
            $this->storageKeyIndex[$aggregate][$storageKey] = $qualifiedKey;
            ksort($this->definitions[$aggregate], SORT_STRING);
        }

        ksort($this->definitions, SORT_STRING);
    }

    #[Override]
    public function has(string $targetAggregate, string $namespace, string $key): bool
    {
        return $this->find($targetAggregate, $namespace, $key) instanceof CustomFieldDefinition;
    }

    #[Override]
    public function get(string $targetAggregate, string $namespace, string $key): CustomFieldDefinition
    {
        return $this->find($targetAggregate, $namespace, $key)
            ?? throw new CustomFieldNotFound($targetAggregate, $namespace, $key);
    }

    #[Override]
    public function find(string $targetAggregate, string $namespace, string $key): ?CustomFieldDefinition
    {
        $storageKey = "{$namespace}:{$key}";
        $qualifiedKey = $this->storageKeyIndex[$targetAggregate][$storageKey] ?? null;

        if ($qualifiedKey === null) {
            return null;
        }

        $candidate = $this->definitions[$targetAggregate][$qualifiedKey] ?? null;

        if ($candidate instanceof CustomFieldDefinition && $candidate->namespace === $namespace && $candidate->key === $key) {
            return $candidate;
        }

        return null;
    }

    #[Override]
    public function forAggregate(string $targetAggregate, ?FieldVisibility $visibility = null): array
    {
        $items = array_values($this->definitions[$targetAggregate] ?? []);

        if ($visibility === null) {
            return $items;
        }

        return array_values(array_filter(
            $items,
            static fn (CustomFieldDefinition $definition): bool => $definition->visibility === $visibility,
        ));
    }

    #[Override]
    public function visibleTo(string $targetAggregate, FieldVisibility $audience): array
    {
        return array_values(array_filter(
            $this->forAggregate($targetAggregate),
            static fn (CustomFieldDefinition $definition): bool => $definition->isVisibleTo($audience),
        ));
    }

    #[Override]
    public function filterableFor(string $targetAggregate, ?FieldVisibility $audience = null): array
    {
        $source = $audience === null
            ? $this->forAggregate($targetAggregate)
            : $this->visibleTo($targetAggregate, $audience);

        return array_values(array_filter(
            $source,
            static fn (CustomFieldDefinition $definition): bool => $definition->isFilterable,
        ));
    }

    #[Override]
    public function all(): array
    {
        $all = [];
        foreach ($this->definitions as $perAggregate) {
            foreach ($perAggregate as $definition) {
                $all[] = $definition;
            }
        }

        return $all;
    }

    #[Override]
    public function prepareValues(
        string $targetAggregate,
        array $values,
        ?MetadataBag $current = null,
        array $remove = [],
        bool $creating = true,
        ?FieldVisibility $actorAudience = null,
    ): PreparedCustomFields {
        $base = $current ?? MetadataBag::empty();
        $resolvedRemovals = [];

        foreach ($remove as $rawRemoveKey) {
            $definition = $this->resolveDefinitionByKey($targetAggregate, $rawRemoveKey);

            if ($actorAudience !== null && ! $definition->isVisibleTo($actorAudience)) {
                throw new CustomFieldVisibilityViolation(
                    $definition->qualifiedKey(),
                    "audience '{$actorAudience->value}' cannot modify '{$definition->visibility->value}' custom field",
                );
            }

            $resolvedRemovals[$definition->qualifiedKey()] = $definition;
        }

        $publicNext = $base->public;
        $privateNext = $base->private;

        foreach ($resolvedRemovals as $qualifiedKey => $definition) {
            unset($publicNext[$qualifiedKey], $publicNext[$definition->storageKey()]);
            unset($privateNext[$qualifiedKey], $privateNext[$definition->storageKey()]);
        }

        foreach ($values as $rawKey => $rawValue) {
            $definition = $this->resolveDefinitionByKey($targetAggregate, $rawKey);
            $qualifiedKey = $definition->qualifiedKey();

            if (isset($resolvedRemovals[$qualifiedKey])) {
                throw new InvalidCommand(
                    'customFields',
                    "'{$qualifiedKey}' cannot be both set and removed",
                    'custom_fields.invalid_command',
                );
            }

            if ($actorAudience !== null && ! $definition->isVisibleTo($actorAudience)) {
                throw new CustomFieldVisibilityViolation(
                    $qualifiedKey,
                    "audience '{$actorAudience->value}' cannot write '{$definition->visibility->value}' custom field",
                );
            }

            if ($rawValue === null) {
                throw new InvalidCustomFieldValue($qualifiedKey, 'null is not a valid value; remove the custom field instead');
            }

            $normalized = CustomFieldValueCodec::normalize($rawValue, $definition);

            unset($publicNext[$definition->storageKey()], $privateNext[$definition->storageKey()]);

            if ($definition->visibility === FieldVisibility::PublicStorefront) {
                unset($privateNext[$qualifiedKey]);
                $publicNext[$qualifiedKey] = $normalized;
            } else {
                unset($publicNext[$qualifiedKey]);
                $privateNext[$qualifiedKey] = $normalized;
            }
        }

        $nextBag = new MetadataBag(public: $publicNext, private: $privateNext);

        return $this->validateMetadataInternal(
            targetAggregate: $targetAggregate,
            metadata: $nextBag,
            creating: $creating,
            removedKeys: array_keys($resolvedRemovals),
        );
    }

    #[Override]
    public function validateMetadata(
        string $targetAggregate,
        MetadataBag $metadata,
        bool $creating = true,
    ): PreparedCustomFields {
        return $this->validateMetadataInternal(
            targetAggregate: $targetAggregate,
            metadata: $metadata,
            creating: $creating,
            removedKeys: [],
        );
    }

    #[Override]
    public function projectMetadata(
        string $targetAggregate,
        MetadataBag $metadata,
        FieldVisibility $audience,
    ): MetadataBag {
        if ($audience === FieldVisibility::PrivateAdmin) {
            return $metadata;
        }

        // Fail-safe defense-in-depth: strip private metadata AND strip any PrivateAdmin field that might appear in public.
        $safePublic = $metadata->public;
        foreach ($this->forAggregate($targetAggregate, FieldVisibility::PrivateAdmin) as $privateDefinition) {
            unset($safePublic[$privateDefinition->qualifiedKey()], $safePublic[$privateDefinition->storageKey()]);
        }

        return new MetadataBag(public: $safePublic, private: []);
    }

    /**
     * @param  list<string>  $removedKeys
     */
    private function validateMetadataInternal(
        string $targetAggregate,
        MetadataBag $metadata,
        bool $creating,
        array $removedKeys,
    ): PreparedCustomFields {
        $publicNext = $metadata->public;
        $privateNext = $metadata->private;

        foreach ($publicNext as $key => $value) {
            $matched = $this->findDefinitionByKey($targetAggregate, $key);
            if ($matched === null) {
                continue;
            }

            if ($matched->visibility !== FieldVisibility::PublicStorefront) {
                throw new CustomFieldVisibilityViolation(
                    $matched->qualifiedKey(),
                    "private admin custom field '{$matched->qualifiedKey()}' must not be stored in publicMetadata",
                );
            }

            $normalized = CustomFieldValueCodec::normalize($value, $matched);
            unset($publicNext[$key]);
            $publicNext[$matched->qualifiedKey()] = $normalized;
        }

        foreach ($privateNext as $key => $value) {
            $matched = $this->findDefinitionByKey($targetAggregate, $key);
            if ($matched === null) {
                continue;
            }

            if ($matched->visibility !== FieldVisibility::PrivateAdmin) {
                throw new CustomFieldVisibilityViolation(
                    $matched->qualifiedKey(),
                    "public storefront custom field '{$matched->qualifiedKey()}' must be stored in publicMetadata, not privateMetadata",
                );
            }

            $normalized = CustomFieldValueCodec::normalize($value, $matched);
            unset($privateNext[$key]);
            $privateNext[$matched->qualifiedKey()] = $normalized;
        }

        $missing = [];
        $rows = [];

        foreach ($this->forAggregate($targetAggregate) as $definition) {
            $qualifiedKey = $definition->qualifiedKey();
            $targetMap = $definition->visibility === FieldVisibility::PublicStorefront ? $publicNext : $privateNext;
            $exists = array_key_exists($qualifiedKey, $targetMap);

            if ($definition->isRequired) {
                $violated = $creating ? ! $exists : (! $exists && in_array($qualifiedKey, $removedKeys, true));
                if ($violated) {
                    $missing[] = $qualifiedKey;
                }
            }

            if ($exists && $definition->isFilterable) {
                foreach (CustomFieldValueCodec::indexFacts($targetMap[$qualifiedKey], $definition) as $fact) {
                    $rows[] = new CustomFieldProjectionRow(
                        namespace: $definition->namespace,
                        key: $definition->key,
                        visibility: $definition->visibility,
                        fact: $fact,
                    );
                }
            }
        }

        if ($missing !== []) {
            throw new MissingRequiredCustomFields($targetAggregate, $missing);
        }

        return new PreparedCustomFields(
            targetAggregate: $targetAggregate,
            metadata: new MetadataBag(public: $publicNext, private: $privateNext),
            rows: $rows,
        );
    }

    private function resolveDefinitionByKey(string $targetAggregate, string $rawKey): CustomFieldDefinition
    {
        $found = $this->findDefinitionByKey($targetAggregate, $rawKey);
        if ($found instanceof CustomFieldDefinition) {
            return $found;
        }

        if (str_contains($rawKey, ':')) {
            [$ns, $k] = explode(':', $rawKey, 2);

            throw new CustomFieldNotFound($targetAggregate, $ns, $k);
        }

        $dotPos = strpos($rawKey, '.');
        if ($dotPos !== false) {
            throw new CustomFieldNotFound(
                $targetAggregate,
                substr($rawKey, 0, $dotPos),
                substr($rawKey, $dotPos + 1),
            );
        }

        throw new CustomFieldNotFound($targetAggregate, '_', $rawKey);
    }

    private function findDefinitionByKey(string $targetAggregate, string $rawKey): ?CustomFieldDefinition
    {
        if (isset($this->definitions[$targetAggregate][$rawKey])) {
            return $this->definitions[$targetAggregate][$rawKey];
        }

        $mapped = $this->storageKeyIndex[$targetAggregate][$rawKey] ?? null;
        if ($mapped !== null && isset($this->definitions[$targetAggregate][$mapped])) {
            return $this->definitions[$targetAggregate][$mapped];
        }

        return null;
    }
}
