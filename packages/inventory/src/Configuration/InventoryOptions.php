<?php

declare(strict_types=1);

namespace Commerz\Inventory\Configuration;

use Commerz\Inventory\Common\Enums\UnlinkedSellables;
use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable compiled configuration snapshot for `axiomasoft/commerz-inventory`.
 */
#[Api(since: '0.1')]
final readonly class InventoryOptions implements OptionsFactory
{
    /** @var list<string> */
    private const array TOP_KEYS = [
        'allocation_strategy',
        'unlinked_sellables',
        'expiry_batch',
    ];

    public function __construct(
        public string $allocationStrategy = 'priority',
        public UnlinkedSellables $unlinkedSellables = UnlinkedSellables::Unavailable,
        public int $expiryBatch = 500,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'commerz-inventory'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, self::TOP_KEYS, $path);

        $strategy = $config['allocation_strategy'] ?? 'priority';

        if (! is_string($strategy) || preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)?\z/', $strategy) !== 1) {
            $validator->add($path.'.allocation_strategy', "expected a strategy key such as 'priority' or 'vendor.name'");
            $strategy = 'priority';
        }

        $unlinked = $config['unlinked_sellables'] ?? UnlinkedSellables::Unavailable;
        $unlinkedEnum = $unlinked instanceof UnlinkedSellables ? $unlinked : (is_string($unlinked) ? UnlinkedSellables::tryFrom($unlinked) : null);

        if ($unlinkedEnum === null) {
            $validator->add($path.'.unlinked_sellables', "expected 'unavailable' or 'unlimited'");
            $unlinkedEnum = UnlinkedSellables::Unavailable;
        }

        $batch = $config['expiry_batch'] ?? 500;

        if (! is_int($batch) || $batch < 1 || $batch > 10000) {
            $validator->add($path.'.expiry_batch', 'expected an integer from 1 to 10000');
            $batch = 500;
        }

        $validator->failIfAny();

        return new self($strategy, $unlinkedEnum, $batch);
    }
}
