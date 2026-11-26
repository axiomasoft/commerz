<?php

declare(strict_types=1);

use Commerz\Configuration\CommerzOptions;
use Commerz\Configuration\StockMode;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Schema\Enums\SchemaScope;

it('reads the shipped config file', function (): void {
    $options = CommerzOptions::fromArray(require __DIR__.'/../../config/commerz.php');

    expect($options->connection)->toBeNull()
        ->and($options->tablePrefix->value())->toBe('cmz_')
        ->and($options->stock)->toBe(StockMode::Inventory)
        ->and($options->customers)->toBeNull()
        ->and($options->plugins)->toBe([])
        ->and($options->disabledPlugins)->toBe([])
        ->and($options->receiptTtlDays)->toBe(30)
        ->and($options->schemaScope)->toBe(SchemaScope::Central);
});

it('defaults every key when the array is empty', function (): void {
    expect(CommerzOptions::fromArray([]))->toEqual(CommerzOptions::fromArray(require __DIR__.'/../../config/commerz.php'));
});

it('accepts valid overrides', function (): void {
    $options = CommerzOptions::fromArray([
        'connection' => 'shop',
        'table_prefix' => 'shop1_',
        'stock' => 'unlimited',
        'customers' => ArrayObject::class,
        'plugins' => [ArrayIterator::class],
        'disabled_plugins' => ['acme.unused'],
        'operations' => ['receipt_ttl_days' => 7],
        'schema_scope' => 'dedicated',
    ]);

    expect($options->connection)->toBe('shop')
        ->and($options->tablePrefix->value())->toBe('shop1_')
        ->and($options->stock)->toBe(StockMode::Unlimited)
        ->and($options->customers)->toBe(ArrayObject::class)
        ->and($options->plugins)->toBe([ArrayIterator::class])
        ->and($options->disabledPlugins)->toBe(['acme.unused'])
        ->and($options->receiptTtlDays)->toBe(7)
        ->and($options->schemaScope)->toBe(SchemaScope::Dedicated);
});

it('rejects unknown keys under closed schema validation', function (): void {
    expect(fn () => CommerzOptions::fromArray(['table_prefx' => 'cmz_'], 'app.commerz'))
        ->toThrow(InvalidConfiguration::class, 'unknown key')
        ->and(fn () => CommerzOptions::fromArray(['operations' => ['unknown_op' => 1]], 'app.commerz'))
        ->toThrow(InvalidConfiguration::class, 'app.commerz.operations.unknown_op');
});

it('names the failing key path', function (array $input, string $path): void {
    try {
        CommerzOptions::fromArray($input, 'app.commerz');
        $this->fail('expected InvalidConfiguration');
    } catch (InvalidConfiguration $e) {
        expect($e->getMessage())->toContain($path);
    }
})->with([
    'empty connection' => [['connection' => ''], 'app.commerz.connection'],
    'connection type' => [['connection' => 5], 'app.commerz.connection'],
    'prefix without underscore' => [['table_prefix' => 'cmz'], 'app.commerz.table_prefix'],
    'prefix uppercase' => [['table_prefix' => 'Cmz_'], 'app.commerz.table_prefix'],
    'prefix too long' => [['table_prefix' => 'abcdefgh_'], 'app.commerz.table_prefix'],
    'prefix too short' => [['table_prefix' => 'a_'], 'app.commerz.table_prefix'],
    'stock value' => [['stock' => 'plenty'], 'app.commerz.stock'],
    'customers class' => [['customers' => 'Nope\\Missing'], 'app.commerz.customers'],
    'plugins shape' => [['plugins' => 'x'], 'app.commerz.plugins'],
    'plugin entry' => [['plugins' => [ArrayIterator::class, 'Nope\\Missing']], 'app.commerz.plugins.1'],
    'disabled plugins shape' => [['disabled_plugins' => 'x'], 'app.commerz.disabled_plugins'],
    'disabled plugin entry' => [['disabled_plugins' => ['InvalidPlugin']], 'app.commerz.disabled_plugins.0'],
    'operations shape' => [['operations' => 5], 'app.commerz.operations'],
    'ttl zero' => [['operations' => ['receipt_ttl_days' => 0]], 'app.commerz.operations.receipt_ttl_days'],
    'ttl type' => [['operations' => ['receipt_ttl_days' => '30']], 'app.commerz.operations.receipt_ttl_days'],
    'table_prefix null' => [['table_prefix' => null], 'app.commerz.table_prefix'],
    'stock null' => [['stock' => null], 'app.commerz.stock'],
    'plugins null' => [['plugins' => null], 'app.commerz.plugins'],
    'disabled_plugins null' => [['disabled_plugins' => null], 'app.commerz.disabled_plugins'],
    'operations null' => [['operations' => null], 'app.commerz.operations'],
    'ttl null' => [['operations' => ['receipt_ttl_days' => null]], 'app.commerz.operations.receipt_ttl_days'],
    'schema_scope value' => [['schema_scope' => 'shared'], 'app.commerz.schema_scope'],
    'schema_scope null' => [['schema_scope' => null], 'app.commerz.schema_scope'],
    'dedicated without connection' => [['schema_scope' => 'dedicated'], 'app.commerz.connection'],
]);
