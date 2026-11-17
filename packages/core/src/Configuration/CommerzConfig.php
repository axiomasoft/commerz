<?php

declare(strict_types=1);

namespace Commerz\Configuration;

use Commerz\Errors\InvalidConfiguration;
use Packman\Attributes\Api;
use Packman\Config\Exceptions\InvalidConfiguration as PackmanInvalidConfiguration;

/**
 * Legacy configuration wrapper retained in Slice A for unmigrated feature subpackages.
 *
 * @deprecated Use CommerzOptions instead.
 */
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

    public static function fromOptions(CommerzOptions $options): self
    {
        return new self(
            connection: $options->connection,
            tablePrefix: $options->tablePrefix->value(),
            stock: $options->stock,
            customers: $options->customers,
            plugins: $options->plugins,
            receiptTtlDays: $options->receiptTtlDays,
        );
    }

    /** @param  array<array-key, mixed>  $config */
    public static function fromArray(array $config, string $path = 'commerz'): self
    {
        try {
            return self::fromOptions(CommerzOptions::fromArray($config, $path));
        } catch (PackmanInvalidConfiguration $exception) {
            $first = $exception->problems()[0] ?? $exception->getMessage();
            $parts = explode(': ', $first, 2);

            throw new InvalidConfiguration(
                path: $parts[0] !== '' ? $parts[0] : $path,
                reason: $parts[1] ?? $exception->getMessage(),
            );
        }
    }
}
