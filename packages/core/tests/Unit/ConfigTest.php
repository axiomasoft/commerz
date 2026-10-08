<?php

declare(strict_types=1);

use Commerz\Configuration\CommerzConfig;
use Commerz\Configuration\StockMode;
use Commerz\Errors\InvalidConfiguration;

it('reads the shipped config file', function (): void {
    $config = CommerzConfig::fromArray(require __DIR__.'/../../config/commerz.php');

    expect($config->connection)->toBeNull()
        ->and($config->tablePrefix)->toBe('cmz_')
        ->and($config->stock)->toBe(StockMode::Inventory)
        ->and($config->customers)->toBeNull()
        ->and($config->plugins)->toBe([])
        ->and($config->receiptTtlDays)->toBe(30);
});

it('defaults every key when the array is empty', function (): void {
    expect(CommerzConfig::fromArray([]))->toEqual(CommerzConfig::fromArray(require __DIR__.'/../../config/commerz.php'));
});

it('accepts valid overrides', function (): void {
    $config = CommerzConfig::fromArray([
        'connection' => 'shop',
        'table_prefix' => 'shop1_',
        'stock' => 'unlimited',
        'customers' => ArrayObject::class,
        'plugins' => [ArrayIterator::class],
        'operations' => ['receipt_ttl_days' => 7],
    ]);

    expect($config->connection)->toBe('shop')
        ->and($config->tablePrefix)->toBe('shop1_')
        ->and($config->stock)->toBe(StockMode::Unlimited)
        ->and($config->customers)->toBe(ArrayObject::class)
        ->and($config->plugins)->toBe([ArrayIterator::class])
        ->and($config->receiptTtlDays)->toBe(7);
});

it('names the failing key path', function (array $input, string $path): void {
    try {
        CommerzConfig::fromArray($input, 'app.commerz');
        $this->fail('expected InvalidConfiguration');
    } catch (InvalidConfiguration $e) {
        expect($e->path)->toBe($path)->and($e->code())->toBe('config.invalid');
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
    'operations shape' => [['operations' => 5], 'app.commerz.operations'],
    'ttl zero' => [['operations' => ['receipt_ttl_days' => 0]], 'app.commerz.operations.receipt_ttl_days'],
    'ttl type' => [['operations' => ['receipt_ttl_days' => '30']], 'app.commerz.operations.receipt_ttl_days'],
]);
