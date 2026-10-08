<?php

declare(strict_types=1);

namespace Commerz\Configuration;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidConfiguration;

/** The only reader of `config('commerz.*')`. A wrong value fails with its key path; there are no silent defaults. */
#[Api(since: '0.1')]
final readonly class CommerzConfig
{
    /**
     * @param  class-string|null  $customers  host CustomerDirectory implementation
     * @param  list<class-string>  $plugins
     */
    public function __construct(
        public ?string $connection,
        public string $tablePrefix,
        public StockMode $stock,
        public ?string $customers,
        public array $plugins,
        public int $receiptTtlDays,
    ) {}

    /** @param  array<array-key, mixed>  $config */
    public static function fromArray(array $config, string $path = 'commerz'): self
    {
        $connection = $config['connection'] ?? null;

        if ($connection !== null && (! is_string($connection) || $connection === '')) {
            throw new InvalidConfiguration("{$path}.connection", 'expected null or a connection name');
        }

        $prefix = $config['table_prefix'] ?? 'cmz_';

        if (! is_string($prefix) || preg_match('/\A[a-z][a-z0-9]{1,6}_\z/', $prefix) !== 1) {
            throw new InvalidConfiguration("{$path}.table_prefix", 'expected 2..7 lowercase letters or digits, starting with a letter and ending with "_"');
        }

        $stock = $config['stock'] ?? StockMode::Inventory->value;
        $stockMode = $stock instanceof StockMode ? $stock : (is_string($stock) ? StockMode::tryFrom($stock) : null);

        if ($stockMode === null) {
            throw new InvalidConfiguration("{$path}.stock", 'expected one of: '.implode(', ', array_column(StockMode::cases(), 'value')));
        }

        $customers = $config['customers'] ?? null;

        if ($customers !== null && (! is_string($customers) || ! class_exists($customers) && ! interface_exists($customers))) {
            throw new InvalidConfiguration("{$path}.customers", 'expected null or an existing class name');
        }

        $pluginList = $config['plugins'] ?? [];

        if (! is_array($pluginList) || ! array_is_list($pluginList)) {
            throw new InvalidConfiguration("{$path}.plugins", 'expected a list of class names');
        }

        $plugins = [];

        foreach ($pluginList as $index => $plugin) {
            if (! is_string($plugin) || ! class_exists($plugin)) {
                throw new InvalidConfiguration("{$path}.plugins.{$index}", 'expected an existing class name');
            }

            $plugins[] = $plugin;
        }

        $operations = $config['operations'] ?? [];

        if (! is_array($operations)) {
            throw new InvalidConfiguration("{$path}.operations", 'expected an array');
        }

        $ttl = $operations['receipt_ttl_days'] ?? 30;

        if (! is_int($ttl) || $ttl < 1 || $ttl > 3650) {
            throw new InvalidConfiguration("{$path}.operations.receipt_ttl_days", 'expected an integer from 1 to 3650');
        }

        return new self($connection, $prefix, $stockMode, $customers, $plugins, $ttl);
    }
}
