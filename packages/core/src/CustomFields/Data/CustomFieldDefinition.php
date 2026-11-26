<?php

declare(strict_types=1);

namespace Commerz\CustomFields\Data;

use Commerz\CustomFields\Enums\CustomFieldType;
use Commerz\CustomFields\Enums\FieldVisibility;
use Commerz\CustomFields\Internal\CustomFieldValueCodec;
use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Packman\Attributes\Api;

/**
 * Immutable definition of a typed custom field attached to a root aggregate (`order`, `customer`, `product`, etc.).
 */
#[Api(since: '0.1')]
final readonly class CustomFieldDefinition
{
    private const string AGGREGATE_PATTERN = '/\A[a-z][a-z0-9_]{0,63}\z/';

    private const string IDENTIFIER_PATTERN = '/\A[a-z][a-z0-9_.\-]{0,63}\z/';

    public LocalizedText $localizedLabel;

    /** @var array<array-key, mixed> */
    public array $validationRules;

    /**
     * @param  array<array-key, mixed>  $validationRules
     */
    public function __construct(
        public string $targetAggregate,
        public string $namespace,
        public string $key,
        public CustomFieldType $type,
        public FieldVisibility $visibility = FieldVisibility::PrivateAdmin,
        public bool $isFilterable = false,
        public bool $isRequired = false,
        array $validationRules = [],
        ?LocalizedText $localizedLabel = null,
    ) {
        if (preg_match(self::AGGREGATE_PATTERN, $targetAggregate) !== 1) {
            throw new InvalidCommand(
                'targetAggregate',
                "'{$targetAggregate}' must match [a-z][a-z0-9_]{0,63}",
                'custom_fields.invalid_definition',
            );
        }

        if (preg_match(self::IDENTIFIER_PATTERN, $namespace) !== 1) {
            throw new InvalidCommand(
                'namespace',
                "'{$namespace}' must match [a-z][a-z0-9_.-]{0,63}",
                'custom_fields.invalid_definition',
            );
        }

        if (preg_match(self::IDENTIFIER_PATTERN, $key) !== 1) {
            throw new InvalidCommand(
                'key',
                "'{$key}' must match [a-z][a-z0-9_.-]{0,63}",
                'custom_fields.invalid_definition',
            );
        }

        if ($isFilterable && ! $type->supportsFiltering()) {
            throw new InvalidCommand(
                'isFilterable',
                "custom field type '{$type->value}' cannot be projected into cmz_custom_field_index",
                'custom_fields.invalid_definition',
            );
        }

        $this->validationRules = CustomFieldValueCodec::validateRules($type, $validationRules);
        $this->localizedLabel = $localizedLabel ?? LocalizedText::of('en', $key);
    }

    /**
     * Canonical metadata map key (`{namespace}:{key}`).
     * Uses `:` to prevent ambiguity when `namespace` or `key` itself contains dots (`acme.crm:deal_stage`).
     */
    public function storageKey(): string
    {
        return "{$this->namespace}:{$this->key}";
    }

    /**
     * Human/diagnostic qualified identifier (`{namespace}.{key}`).
     */
    public function qualifiedKey(): string
    {
        return "{$this->namespace}.{$this->key}";
    }

    public function label(string $locale, string $fallback = 'en'): string
    {
        return $this->localizedLabel->resolve($locale, $fallback);
    }

    public function isVisibleTo(FieldVisibility $audience): bool
    {
        return $audience->canAccess($this->visibility);
    }
}
