<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Plugins\CommerzPlugins;
use Commerz\Plugins\Contracts\DescribesConfigSchema;
use Commerz\Plugins\Data\ConfigFieldSchema;
use Commerz\Plugins\Enums\ConfigFieldType;
use Commerz\Plugins\PluginInspector;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

final class ConfigurableDemoPlugin implements DescribesConfigSchema, ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.configurable', '1.0.0', ['commerz.core']);
    }

    public function register(PluginRegistrar $registrar): void {}

    public function configSchema(): array
    {
        return [
            new ConfigFieldSchema(
                key: 'api_key',
                type: ConfigFieldType::String,
                label: 'API Key',
                required: true,
                description: 'Secret token for external provider',
                placeholder: 'sk_live_...',
                group: 'credentials',
                widget: 'password',
                secret: true,
                validationRules: ['required', 'string', 'min:10'],
                uiHints: ['autocomplete' => 'off'],
            ),
            new ConfigFieldSchema(key: 'retry_limit', type: ConfigFieldType::Int, label: 'Retry Limit', default: 3, validationRules: ['integer', 'min:0', 'max:10']),
            new ConfigFieldSchema(key: 'weight_factor', type: ConfigFieldType::Decimal, label: 'Weight Factor', default: '1.25'),
            new ConfigFieldSchema(key: 'min_order', type: ConfigFieldType::Money, label: 'Minimum Order'),
            new ConfigFieldSchema(key: 'handling_fee', type: ConfigFieldType::Percent, label: 'Handling Fee', default: '2.50'),
            new ConfigFieldSchema(key: 'sandbox', type: ConfigFieldType::Bool, label: 'Sandbox Mode', default: false),
            new ConfigFieldSchema(key: 'mode', type: ConfigFieldType::Enum, label: 'Mode', options: ['sync' => 'Synchronous', 'async' => 'Asynchronous'], default: 'sync'),
            new ConfigFieldSchema(key: 'checkout_note', type: ConfigFieldType::LocalizedString, label: 'Checkout Note'),
            new ConfigFieldSchema(key: 'default_channel', type: ConfigFieldType::EntityRef, label: 'Default Channel', entityType: 'channel'),
        ];
    }
}

final class PlainDemoPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.plain', '1.0.0', ['commerz.core']);
    }

    public function register(PluginRegistrar $registrar): void {}
}

it('validates ConfigFieldSchema invariants and serializes all field types', function (): void {
    $field = new ConfigFieldSchema(
        key: 'provider.mode',
        type: ConfigFieldType::Enum,
        label: 'Provider Mode',
        required: true,
        default: 'live',
        description: 'Execution mode',
        placeholder: 'Select mode',
        group: 'general',
        widget: 'select',
        options: ['live', 'test'],
        validationRules: ['in:live,test'],
        uiHints: ['columns' => 2],
    );

    expect($field->toArray())->toBe([
        'key' => 'provider.mode',
        'type' => 'enum',
        'label' => 'Provider Mode',
        'required' => true,
        'default' => 'live',
        'description' => 'Execution mode',
        'placeholder' => 'Select mode',
        'group' => 'general',
        'widget' => 'select',
        'secret' => false,
        'options' => ['live', 'test'],
        'entity_type' => null,
        'validation_rules' => ['in:live,test'],
        'ui_hints' => ['columns' => 2],
    ]);

    expect(fn () => new ConfigFieldSchema('Bad Key', ConfigFieldType::String, 'Label'))
        ->toThrow(InvalidCommand::class)
        ->and(fn () => new ConfigFieldSchema('valid_key', ConfigFieldType::String, '   '))
        ->toThrow(InvalidCommand::class)
        ->and(fn () => new ConfigFieldSchema('mode', ConfigFieldType::Enum, 'Mode'))
        ->toThrow(InvalidCommand::class)
        ->and(fn () => new ConfigFieldSchema('channel', ConfigFieldType::EntityRef, 'Channel'))
        ->toThrow(InvalidCommand::class);
});

it('discovers schemas via PluginInspector::describeSchemas() for plugins implementing DescribesConfigSchema', function (): void {
    CommerzPlugins::builtIn(ConfigurableDemoPlugin::class, PlainDemoPlugin::class);

    $inspector = app(PluginInspector::class);
    $schemas = $inspector->describeSchemas();

    expect(array_keys($schemas))->toBe(['test.configurable'])
        ->and($schemas['test.configurable'])->toHaveCount(9)
        ->and(array_map(static fn (ConfigFieldSchema $f): string => $f->type->value, $schemas['test.configurable']))
        ->toBe([
            'string',
            'int',
            'decimal',
            'money',
            'percent',
            'bool',
            'enum',
            'localized_string',
            'entity_ref',
        ])
        ->and(array_column($inspector->describe(), 'id'))->toBe(['commerz.core', 'test.configurable', 'test.plain']);
});
