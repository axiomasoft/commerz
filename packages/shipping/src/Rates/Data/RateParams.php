<?php

declare(strict_types=1);

namespace Commerz\Shipping\Rates\Data;

use BackedEnum;
use Commerz\Attributes\Api;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Shipping\Rates\Exceptions\InvalidRateConfig;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * The parameters of a rate as a canonical JSON-safe map: keys sorted, `Money` as `{amount_minor, currency}`, enums as
 * their value, dates as ISO-8601 UTC. No floats and no other objects, so what is stored is what was meant and two
 * equal configurations are equal byte for byte. A field is named `key` or `key.<index>.<sub>`.
 *
 * ```php
 * $amount = $params->only(['amount'])->money('amount', $scope->currency);
 * $steps = $params->list('steps', 1, 100);
 * ```
 */
#[Api(since: '0.1')]
final readonly class RateParams
{
    private const int MAX_DEPTH = 8;

    /** @param  array<string, mixed>  $values */
    private function __construct(private array $values) {}

    /**
     * @param  array<array-key, mixed>  $input  scalars, `Money`, `BackedEnum`, `DateTimeInterface` and arrays of them
     *
     * @throws InvalidRateConfig a float, an object of another kind, a list at the top, or a map with non-string keys
     */
    public static function fromInput(array $input): self
    {
        if ($input !== [] && array_is_list($input)) {
            throw new InvalidRateConfig('params', 'expected a map of named parameters, not a list');
        }

        $values = self::canonical($input, '', 0);

        /** @var array<string, mixed> $values */
        return new self($values);
    }

    /**
     * Parameters read from the database (decoded JSON): the same canonical form as `fromInput`.
     *
     * @param  array<array-key, mixed>  $stored
     *
     * @throws InvalidRateConfig when the stored value is not a canonical map
     */
    public static function fromStored(array $stored): self
    {
        return self::fromInput($stored);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * Refuses a parameter that is not in the list, so a typo does not pass as a rate that does nothing.
     *
     * @param  list<string>  $keys
     *
     * @throws InvalidRateConfig
     */
    public function only(array $keys): self
    {
        foreach (array_keys($this->values) as $key) {
            if (! in_array($key, $keys, true)) {
                throw new InvalidRateConfig($key, 'unknown parameter, expected: '.implode(', ', $keys));
            }
        }

        return $this;
    }

    /** @throws InvalidRateConfig */
    public function int(string $key, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        return $this->intValue($key, $this->required($key), $min, $max);
    }

    /** @throws InvalidRateConfig */
    public function nullableInt(string $key, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): ?int
    {
        $value = $this->values[$key] ?? null;

        return $value === null ? null : $this->intValue($key, $value, $min, $max);
    }

    /** @throws InvalidRateConfig a missing or malformed amount, or a currency other than the tariff's */
    public function money(string $key, Currency $currency): Money
    {
        $value = $this->required($key);
        $shape = is_array($value) ? self::moneyShape($value) : null;

        if ($shape === null) {
            throw new InvalidRateConfig($key, 'expected an amount: {amount_minor, currency}');
        }

        if ($shape[1] !== $currency->code) {
            throw new InvalidRateConfig($key, "must be in {$currency->code}");
        }

        return Money::ofMinor($shape[0], $currency);
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws InvalidRateConfig not a list of maps, or a number of items outside the bounds
     */
    public function list(string $key, int $min, int $max): array
    {
        $value = $this->required($key);

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidRateConfig($key, 'expected a list');
        }

        if (count($value) < $min || count($value) > $max) {
            throw new InvalidRateConfig($key, "expected from {$min} to {$max} items");
        }

        $items = [];

        foreach ($value as $index => $item) {
            if (! is_array($item) || ($item !== [] && array_is_list($item))) {
                throw new InvalidRateConfig("{$key}.{$index}", 'expected a map');
            }

            /** @var array<string, mixed> $item */
            $items[] = $item;
        }

        return $items;
    }

    private function required(string $key): mixed
    {
        if (! array_key_exists($key, $this->values) || $this->values[$key] === null) {
            throw new InvalidRateConfig($key, 'is required');
        }

        return $this->values[$key];
    }

    private function intValue(string $key, mixed $value, int $min, int $max): int
    {
        if (! is_int($value)) {
            throw new InvalidRateConfig($key, 'expected an integer');
        }

        if ($value < $min || $value > $max) {
            throw new InvalidRateConfig($key, "expected an integer from {$min} to {$max}");
        }

        return $value;
    }

    /** @return array{int, string}|null */
    private static function moneyShape(mixed $value): ?array
    {
        if (! is_array($value) || array_keys($value) !== ['amount_minor', 'currency']) {
            return null;
        }

        return is_int($value['amount_minor']) && is_string($value['currency']) ? [$value['amount_minor'], $value['currency']] : null;
    }

    private static function canonical(mixed $value, string $path, int $depth): mixed
    {
        $field = $path === '' ? 'params' : $path;

        return match (true) {
            $value === null, is_bool($value), is_int($value), is_string($value) => $value,
            is_float($value) => throw new InvalidRateConfig($field, 'a float is not allowed; use an integer (minor units) or a string'),
            $value instanceof Money => ['amount_minor' => $value->amountMinor, 'currency' => $value->currency->code],
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            is_array($value) => self::canonicalArray($value, $path, $depth),
            default => throw new InvalidRateConfig($field, 'a '.get_debug_type($value).' is not allowed'),
        };
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function canonicalArray(array $value, string $path, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            throw new InvalidRateConfig($path === '' ? 'params' : $path, 'is nested deeper than '.self::MAX_DEPTH.' levels');
        }

        $child = static fn (string|int $key): string => $path === '' ? (string) $key : "{$path}.{$key}";

        if (array_is_list($value)) {
            $items = [];

            foreach ($value as $index => $item) {
                $items[] = self::canonical($item, $child($index), $depth + 1);
            }

            return $items;
        }

        $canonical = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidRateConfig($path === '' ? 'params' : $path, 'a map must have string keys');
            }

            $canonical[$key] = self::canonical($item, $child($key), $depth + 1);
        }

        ksort($canonical);

        return $canonical;
    }
}
