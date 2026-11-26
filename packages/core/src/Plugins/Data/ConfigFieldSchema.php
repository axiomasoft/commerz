<?php

declare(strict_types=1);

namespace Commerz\Plugins\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Plugins\Enums\ConfigFieldType;
use Packman\Attributes\Api;

/**
 * Typed schema descriptor for a single plugin configuration field, carrying type metadata,
 * UI rendering hints, and validation rules for admin panels and host validators.
 */
#[Api(since: '0.1')]
final readonly class ConfigFieldSchema
{
    /**
     * @param  list<string>|array<string, string>  $options  allowed values for {@see ConfigFieldType::Enum}
     * @param  list<string>  $validationRules  validation rules (e.g. `min:0`, `max:255`)
     * @param  array<string, mixed>  $uiHints  additional UI rendering hints
     */
    public function __construct(
        public string $key,
        public ConfigFieldType $type,
        public string $label,
        public bool $required = false,
        public mixed $default = null,
        public ?string $description = null,
        public ?string $placeholder = null,
        public ?string $group = null,
        public ?string $widget = null,
        public bool $secret = false,
        public array $options = [],
        public ?string $entityType = null,
        public array $validationRules = [],
        public array $uiHints = [],
    ) {
        if (preg_match('/\A[a-z][a-z0-9_.\-]{0,127}\z/', $this->key) !== 1) {
            throw new InvalidCommand('key', "'{$this->key}' must match [a-z][a-z0-9_.-]{0,127}", 'plugins.invalid_config_field_key');
        }

        if (trim($this->label) === '') {
            throw new InvalidCommand('label', 'field label must not be empty', 'plugins.invalid_config_field_label');
        }

        if ($this->type === ConfigFieldType::Enum && $this->options === []) {
            throw new InvalidCommand('options', "enum field '{$this->key}' must declare at least one option", 'plugins.missing_enum_options');
        }

        if ($this->type === ConfigFieldType::EntityRef && ($this->entityType === null || trim($this->entityType) === '')) {
            throw new InvalidCommand('entityType', "entity_ref field '{$this->key}' must declare an entityType", 'plugins.missing_entity_type');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'label' => $this->label,
            'required' => $this->required,
            'default' => $this->default,
            'description' => $this->description,
            'placeholder' => $this->placeholder,
            'group' => $this->group,
            'widget' => $this->widget,
            'secret' => $this->secret,
            'options' => $this->options,
            'entity_type' => $this->entityType,
            'validation_rules' => $this->validationRules,
            'ui_hints' => $this->uiHints,
        ];
    }
}
