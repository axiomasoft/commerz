<?php

declare(strict_types=1);

use Commerz\Errors\InvalidConfiguration;
use Commerz\Inventory\Common\Enums\UnlinkedSellables;
use Commerz\Inventory\Common\Internal\InventoryConfig;

it('defaults to priority allocation, unavailable unlinked sellables and a 500 batch', function (): void {
    $config = InventoryConfig::fromArray([]);

    expect($config->allocationStrategy)->toBe('priority')
        ->and($config->unlinkedSellables)->toBe(UnlinkedSellables::Unavailable)
        ->and($config->expiryBatch)->toBe(500);
});

it('reads every key', function (): void {
    $config = InventoryConfig::fromArray(['allocation_strategy' => 'acme.nearest', 'unlinked_sellables' => 'unlimited', 'expiry_batch' => 10]);

    expect($config->allocationStrategy)->toBe('acme.nearest')
        ->and($config->unlinkedSellables)->toBe(UnlinkedSellables::Unlimited)
        ->and($config->expiryBatch)->toBe(10);
});

it('names the offending key', function (array $config, string $path): void {
    expect(fn () => InventoryConfig::fromArray($config))
        ->toThrow(fn (InvalidConfiguration $e) => expect($e->path)->toBe($path));
})->with([
    'strategy not a string' => [['allocation_strategy' => 1], 'commerz-inventory.allocation_strategy'],
    'strategy malformed' => [['allocation_strategy' => 'Nearest One'], 'commerz-inventory.allocation_strategy'],
    'unlinked unknown' => [['unlinked_sellables' => 'ignore'], 'commerz-inventory.unlinked_sellables'],
    'unlinked not a string' => [['unlinked_sellables' => true], 'commerz-inventory.unlinked_sellables'],
    'batch zero' => [['expiry_batch' => 0], 'commerz-inventory.expiry_batch'],
    'batch too large' => [['expiry_batch' => 10001], 'commerz-inventory.expiry_batch'],
    'batch not an int' => [['expiry_batch' => '50'], 'commerz-inventory.expiry_batch'],
]);
