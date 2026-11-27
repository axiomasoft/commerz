<?php

declare(strict_types=1);

use Commerz\Inventory\Common\Enums\UnlinkedSellables;
use Commerz\Inventory\Configuration\InventoryOptions;
use Packman\Config\Exceptions\InvalidConfiguration;

it('defaults to priority allocation, unavailable unlinked sellables and a 500 batch', function (): void {
    $published = require __DIR__.'/../../config/commerz-inventory.php';

    if (! is_array($published)) {
        throw new RuntimeException('commerz-inventory.php must return an array');
    }

    $config = InventoryOptions::fromArray([]);

    expect($config->allocationStrategy)->toBe('priority')
        ->and($config->unlinkedSellables)->toBe(UnlinkedSellables::Unavailable)
        ->and($config->expiryBatch)->toBe(500)
        ->and(InventoryOptions::fromArray($published))->toEqual($config);
});

it('reads every key', function (): void {
    $config = InventoryOptions::fromArray(['allocation_strategy' => 'acme.nearest', 'unlinked_sellables' => 'unlimited', 'expiry_batch' => 10]);

    expect($config->allocationStrategy)->toBe('acme.nearest')
        ->and($config->unlinkedSellables)->toBe(UnlinkedSellables::Unlimited)
        ->and($config->expiryBatch)->toBe(10);
});

it('rejects unknown keys under closed schema validation', function (): void {
    expect(fn () => InventoryOptions::fromArray(['unknown_key' => 1], 'app.inventory'))
        ->toThrow(InvalidConfiguration::class, 'app.inventory.unknown_key');
});

it('names the offending key', function (array $config, string $path): void {
    expect(fn () => InventoryOptions::fromArray($config))
        ->toThrow(InvalidConfiguration::class, $path);
})->with([
    'strategy not a string' => [['allocation_strategy' => 1], 'commerz-inventory.allocation_strategy'],
    'strategy malformed' => [['allocation_strategy' => 'Nearest One'], 'commerz-inventory.allocation_strategy'],
    'unlinked unknown' => [['unlinked_sellables' => 'ignore'], 'commerz-inventory.unlinked_sellables'],
    'unlinked not a string' => [['unlinked_sellables' => true], 'commerz-inventory.unlinked_sellables'],
    'batch zero' => [['expiry_batch' => 0], 'commerz-inventory.expiry_batch'],
    'batch too large' => [['expiry_batch' => 10001], 'commerz-inventory.expiry_batch'],
    'batch not an int' => [['expiry_batch' => '50'], 'commerz-inventory.expiry_batch'],
    'strategy null' => [['allocation_strategy' => null], 'commerz-inventory.allocation_strategy'],
    'unlinked null' => [['unlinked_sellables' => null], 'commerz-inventory.unlinked_sellables'],
    'batch null' => [['expiry_batch' => null], 'commerz-inventory.expiry_batch'],
]);
