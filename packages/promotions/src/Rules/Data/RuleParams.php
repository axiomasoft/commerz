<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Data;

use BackedEnum;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Packman\Attributes\Api;

/**
 * The parameters of a rule as a canonical JSON-safe map: keys sorted, `Money` as `{amount_minor, currency}`, enums as
 * their value, dates as ISO-8601 UTC. No floats and no other objects, so what is stored is what was meant and two
 * equal configurations are equal byte for byte.
 *
 * ```php
 * $minimum = $params->only(['amount'])->money('amount', $scope->currencyFor('amount'));
 * ```
 */
#[Api(since: '0.1')]
final readonly class RuleParams
{
    private const int MAX_DEPTH = 8;

    /** @param  array<string, mixed>  $values */
    private function __construct(private array $values) {}

    /**
     * @param  array<array-key, mixed>  $input  scalars, `Money`, `BackedEnum`, `DateTimeInterface` and arrays of them
     *
     * @throws InvalidRuleConfig a float, an object of another kind, a list at the top, or a map with non-string keys
     */
    public static function fromInput(array $input): self
    {
        if ($input !== [] && array_is_list($input)) {
            throw new InvalidRuleConfig('params', 'expected a map of named parameters, not a list');
        }

        $values = self::canonical($input, '', 0);

        /** @var array<string, mixed> $values */
        return new self($values);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * Refuses a parameter that is not in the list, so a typo does not pass as a rule that does nothing.
     *
     * @param  list<string>  $keys
     *
     * @throws InvalidRuleConfig
     */
    public function only(array $keys): self
    {
        foreach (array_keys($this->values) as $key) {
            if (! in_array($key, $keys, true)) {
                throw new InvalidRuleConfig($key, 'unknown parameter, expected: '.implode(', ', $keys));
            }
        }

        return $this;
    }

    /** @throws InvalidRuleConfig */
    public function int(string $key, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        return $this->intValue($key, $this->required($key), $min, $max);
    }

    /** @throws InvalidRuleConfig */
    public function nullableInt(string $key, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): ?int
    {
        $value = $this->values[$key] ?? null;

        return $value === null ? null : $this->intValue($key, $value, $min, $max);
    }

    /**
     * @param  non-empty-string|null  $pattern  a PCRE the whole value must match
     *
     * @throws InvalidRuleConfig
     */
    public function string(string $key, ?string $pattern = null): string
    {
        return $this->stringValue($key, $this->required($key), $pattern);
    }

    /**
     * @param  non-empty-string|null  $pattern  a PCRE every item must match
     * @return list<string>
     *
     * @throws InvalidRuleConfig
     */
    public function stringList(string $key, int $min, int $max, ?string $pattern = null): array
    {
        $value = $this->required($key);

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidRuleConfig($key, 'expected a list of strings');
        }

        if (count($value) < $min || count($value) > $max) {
            throw new InvalidRuleConfig($key, "expected from {$min} to {$max} items");
        }

        $items = [];

        foreach ($value as $item) {
            $items[] = $this->stringValue($key, $item, $pattern);
        }

        return $items;
    }

    /** @throws InvalidRuleConfig a missing or malformed amount, or a currency other than the promotion's */
    public function money(string $key, Currency $currency): Money
    {
        $value = $this->required($key);
        $shape = is_array($value) ? self::moneyShape($value) : null;

        if ($shape === null) {
            throw new InvalidRuleConfig($key, 'expected an amount: {amount_minor, currency}');
        }

        if ($shape[1] !== $currency->code) {
            throw new InvalidRuleConfig($key, "currency must be {$currency->code}, got {$shape[1]}");
        }

        return Money::ofMinor($shape[0], $currency);
    }

    /**
     * @param  non-empty-list<string>  $allowed
     *
     * @throws InvalidRuleConfig
     */
    public function choice(string $key, array $allowed, ?string $default = null): string
    {
        if (! array_key_exists($key, $this->values) && $default !== null) {
            return $default;
        }

        $value = $this->stringValue($key, $this->required($key), null);

        if (! in_array($value, $allowed, true)) {
            throw new InvalidRuleConfig($key, 'expected one of: '.implode(', ', $allowed));
        }

        return $value;
    }

    /**
     * The currencies of all the `Money` values inside, sorted and without repeats: what a promotion without its own
     * currency would have to take from its rules.
     *
     * @return list<string>
     */
    public function currencies(): array
    {
        $found = [];
        self::collectCurrencies($this->values, $found);
        $codes = array_keys($found);
        sort($codes);

        return $codes;
    }

    private function required(string $key): mixed
    {
        if (! array_key_exists($key, $this->values) || $this->values[$key] === null) {
            throw new InvalidRuleConfig($key, 'is required');
        }

        return $this->values[$key];
    }

    private function intValue(string $key, mixed $value, int $min, int $max): int
    {
        if (! is_int($value)) {
            throw new InvalidRuleConfig($key, 'expected an integer');
        }

        if ($value < $min || $value > $max) {
            throw new InvalidRuleConfig($key, "expected an integer from {$min} to {$max}");
        }

        return $value;
    }

    private function stringValue(string $key, mixed $value, ?string $pattern): string
    {
        if (! is_string($value)) {
            throw new InvalidRuleConfig($key, 'expected a string');
        }

        if ($pattern !== null && preg_match($pattern, $value) !== 1) {
            throw new InvalidRuleConfig($key, "'{$value}' does not match {$pattern}");
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

    /** @param  array<string, true>  $found */
    private static function collectCurrencies(mixed $node, array &$found): void
    {
        if (! is_array($node)) {
            return;
        }

        $shape = self::moneyShape($node);

        if ($shape !== null) {
            $found[$shape[1]] = true;

            return;
        }

        foreach ($node as $child) {
            self::collectCurrencies($child, $found);
        }
    }

    private static function canonical(mixed $value, string $path, int $depth): mixed
    {
        $field = $path === '' ? 'params' : $path;

        return match (true) {
            $value === null, is_bool($value), is_int($value), is_string($value) => $value,
            is_float($value) => throw new InvalidRuleConfig($field, 'a float is not allowed; use an integer (basis points, minor units) or a string'),
            $value instanceof Money => ['amount_minor' => $value->amountMinor, 'currency' => $value->currency->code],
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            is_array($value) => self::canonicalArray($value, $path, $depth),
            default => throw new InvalidRuleConfig($field, 'a '.get_debug_type($value).' is not allowed'),
        };
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function canonicalArray(array $value, string $path, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            throw new InvalidRuleConfig($path === '' ? 'params' : $path, 'is nested deeper than '.self::MAX_DEPTH.' levels');
        }

        $child = static fn (string|int $key): string => $path === '' ? (string) $key : "{$path}.{$key}";

        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::canonical($item, $path, $depth + 1), $value);
        }

        $canonical = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidRuleConfig($path === '' ? 'params' : $path, 'a map must have string keys');
            }

            $canonical[$key] = self::canonical($item, $child($key), $depth + 1);
        }

        ksort($canonical);

        return $canonical;
    }
}
