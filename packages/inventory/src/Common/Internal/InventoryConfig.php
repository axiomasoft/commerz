<?php

declare(strict_types=1);

namespace Commerz\Inventory\Common\Internal;

use Commerz\Errors\InvalidConfiguration;
use Commerz\Inventory\Common\Enums\UnlinkedSellables;

/** The only reader of `config('commerz-inventory.*')`. */
final readonly class InventoryConfig
{
    public function __construct(
        public string $allocationStrategy,
        public UnlinkedSellables $unlinkedSellables,
        public int $expiryBatch,
    ) {}

    /** @param  array<array-key, mixed>  $config */
    public static function fromArray(array $config, string $path = 'commerz-inventory'): self
    {
        $strategy = $config['allocation_strategy'] ?? 'priority';

        if (! is_string($strategy) || preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)?\z/', $strategy) !== 1) {
            throw new InvalidConfiguration("{$path}.allocation_strategy", "expected a strategy key such as 'priority' or 'vendor.name'");
        }

        $unlinked = $config['unlinked_sellables'] ?? UnlinkedSellables::Unavailable;
        $unlinked = $unlinked instanceof UnlinkedSellables ? $unlinked : (is_string($unlinked) ? UnlinkedSellables::tryFrom($unlinked) : null);

        if ($unlinked === null) {
            throw new InvalidConfiguration("{$path}.unlinked_sellables", "expected 'unavailable' or 'unlimited'");
        }

        $batch = $config['expiry_batch'] ?? 500;

        if (! is_int($batch) || $batch < 1 || $batch > 10000) {
            throw new InvalidConfiguration("{$path}.expiry_batch", 'expected an integer from 1 to 10000');
        }

        return new self($strategy, $unlinked, $batch);
    }
}
