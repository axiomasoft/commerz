<?php

declare(strict_types=1);

namespace Commerz\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Data\TablePrefix;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\ConfigurationValidator;
use Packman\Plugins\Data\Plugin\PluginManifest;

/**
 * Immutable compiled configuration snapshot for `axiomasoft/commerz`.
 */
#[Api(since: '0.1')]
final readonly class CommerzOptions implements OptionsFactory
{
    /** @var list<string> */
    private const array TOP_KEYS = [
        'connection',
        'table_prefix',
        'stock',
        'customers',
        'plugins',
        'disabled_plugins',
        'operations',
    ];

    /** @var list<string> */
    private const array OPERATION_KEYS = [
        'receipt_ttl_days',
    ];

    /**
     * @param  class-string|null  $customers  host CustomerDirectory implementation
     * @param  list<class-string>  $plugins
     * @param  list<string>  $disabledPlugins
     */
    public function __construct(
        public ?string $connection = null,
        public TablePrefix $tablePrefix = new TablePrefix('cmz_'),
        public StockMode $stock = StockMode::Inventory,
        public ?string $customers = null,
        public array $plugins = [],
        public array $disabledPlugins = [],
        public int $receiptTtlDays = 30,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'commerz'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, self::TOP_KEYS, $path);

        $connection = $config['connection'] ?? null;

        if ($connection !== null && (! is_string($connection) || $connection === '')) {
            $validator->add($path.'.connection', 'expected null or a non-empty database connection name');
            $connection = null;
        }

        $tablePrefix = new TablePrefix('cmz_');

        try {
            $tablePrefix = TablePrefix::fromArrayValue(
                array_key_exists('table_prefix', $config) ? $config['table_prefix'] : 'cmz_',
                $path.'.table_prefix',
            );
        } catch (InvalidConfiguration $exception) {
            foreach ($exception->problems() as $problem) {
                $prefix = $path.'.table_prefix: ';
                $validator->add($path.'.table_prefix', str_starts_with($problem, $prefix) ? substr($problem, strlen($prefix)) : $problem);
            }
        }

        $stock = array_key_exists('stock', $config) ? $config['stock'] : StockMode::Inventory->value;
        $stockMode = $stock instanceof StockMode ? $stock : (is_string($stock) ? StockMode::tryFrom($stock) : null);

        if ($stockMode === null) {
            $validator->add($path.'.stock', 'expected one of: '.implode(', ', array_column(StockMode::cases(), 'value')));
            $stockMode = StockMode::Inventory;
        }

        $customers = $config['customers'] ?? null;

        if ($customers !== null && (! is_string($customers) || (! class_exists($customers) && ! interface_exists($customers)))) {
            $validator->add($path.'.customers', 'expected null or an existing class name');
            $customers = null;
        }

        /** @var list<class-string> $plugins */
        $plugins = self::validateClassList(
            array_key_exists('plugins', $config) ? $config['plugins'] : [],
            $path.'.plugins',
            $validator,
        );

        $disabledPlugins = self::validatePluginIds(
            array_key_exists('disabled_plugins', $config) ? $config['disabled_plugins'] : [],
            $path.'.disabled_plugins',
            $validator,
        );

        $operations = array_key_exists('operations', $config) ? $config['operations'] : [];
        $ttl = 30;

        if (! is_array($operations)) {
            $validator->add($path.'.operations', 'expected an array');
        } else {
            $validator->rejectUnknownKeys($operations, self::OPERATION_KEYS, $path.'.operations');
            $rawTtl = array_key_exists('receipt_ttl_days', $operations) ? $operations['receipt_ttl_days'] : 30;

            if (! is_int($rawTtl) || $rawTtl < 1 || $rawTtl > 3650) {
                $validator->add($path.'.operations.receipt_ttl_days', 'expected an integer from 1 to 3650');
            } else {
                $ttl = $rawTtl;
            }
        }

        $validator->failIfAny();

        /** @var ?class-string $customers */
        return new self($connection, $tablePrefix, $stockMode, $customers, $plugins, $disabledPlugins, $ttl);
    }

    /** @return list<class-string> */
    private static function validateClassList(mixed $raw, string $path, ConfigurationValidator $validator): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $validator->add($path, 'expected a list of class names');

            return [];
        }

        $classes = [];

        foreach ($raw as $index => $plugin) {
            if (! is_string($plugin) || ! class_exists($plugin)) {
                $validator->add($path.'.'.$index, 'expected an existing class name');

                continue;
            }

            $classes[] = $plugin;
        }

        return $classes;
    }

    /** @return list<string> */
    private static function validatePluginIds(mixed $raw, string $path, ConfigurationValidator $validator): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $validator->add($path, 'expected a list of plugin ids');

            return [];
        }

        $ids = [];

        foreach ($raw as $index => $id) {
            if (! is_string($id) || preg_match(PluginManifest::ID_PATTERN, $id) !== 1) {
                $validator->add($path.'.'.$index, 'expected a valid plugin id');

                continue;
            }

            $ids[] = $id;
        }

        return $ids;
    }
}
