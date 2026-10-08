<?php

declare(strict_types=1);

namespace Commerz\Config\Data;

use Commerz\Attributes\Api;
use Commerz\Config\Enums\AvailabilitySource;
use Commerz\Config\Exceptions\InvalidConfiguration;
use Commerz\Money\Enums\RoundingMode;
use Commerz\Plugins\Contracts\CommerzPlugin;
use Illuminate\Contracts\Config\Repository;

/** Единственная точка чтения config('commerz.*'). Неверное значение — исключение с путём ключа, не дефолт. */
#[Api(since: '1.0')]
final readonly class CommerzConfig
{
    /** @param list<class-string<CommerzPlugin>> $plugins */
    public function __construct(
        public ?string $connection,
        public string $tablePrefix,
        public RoundingMode $currencyRounding,
        public AvailabilitySource $availability,
        public array $plugins,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        $prefix = $config->get('commerz.table_prefix', 'cmz_');
        if (! is_string($prefix) || preg_match('/\A[a-z][a-z0-9]{1,7}_\z/D', $prefix) !== 1) {
            throw InvalidConfiguration::at('commerz.table_prefix', 'expected 2..8 lowercase chars ending with "_"');
        }

        $connection = $config->get('commerz.connection');
        if ($connection !== null && (! is_string($connection) || $connection === '')) {
            throw InvalidConfiguration::at('commerz.connection', 'expected null or connection name');
        }

        $plugins = [];
        foreach ((array) $config->get('commerz.plugins', []) as $index => $class) {
            if (! is_string($class) || ! is_subclass_of($class, CommerzPlugin::class)) {
                throw InvalidConfiguration::at("commerz.plugins.{$index}", 'expected class-string of CommerzPlugin');
            }
            $plugins[] = $class;
        }

        return new self(
            connection: $connection,
            tablePrefix: $prefix,
            currencyRounding: self::enum(RoundingMode::class, $config, 'commerz.currency_rounding'),
            availability: self::enum(AvailabilitySource::class, $config, 'commerz.availability'),
            plugins: $plugins,
        );
    }

    /**
     * @template T of \BackedEnum
     * @param  class-string<T>  $enum
     * @return T
     */
    private static function enum(string $enum, Repository $config, string $key): \BackedEnum
    {
        $raw = $config->get($key);
        $case = is_string($raw) ? $enum::tryFrom($raw) : null;

        return $case ?? throw InvalidConfiguration::at($key, 'expected one of: '.implode(', ', array_column($enum::cases(), 'value')));
    }
}
