<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Internal;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\Exception\RoundingNecessaryException;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeConfig;

/** Shared checks for attribute type configs. */
final class ConfigReader
{
    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $allowed
     */
    public static function only(array $config, array $allowed): void
    {
        foreach (array_keys($config) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new InvalidAttributeConfig($key, 'unknown option');
            }
        }
    }

    /** @param  array<string, mixed>  $config */
    public static function int(array $config, string $key, int $min, int $max, ?int $default): ?int
    {
        if (! array_key_exists($key, $config)) {
            return $default;
        }

        $value = $config[$key];

        if (! is_int($value) || $value < $min || $value > $max) {
            throw new InvalidAttributeConfig($key, "must be an integer from {$min} to {$max}");
        }

        return $value;
    }

    /** @param  array<string, mixed>  $config */
    public static function bound(array $config, string $key, int $scale): ?BigDecimal
    {
        if (! array_key_exists($key, $config)) {
            return null;
        }

        $value = $config[$key];

        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || preg_match('/\A-?\d+(\.\d+)?\z/', $value) !== 1) {
            throw new InvalidAttributeConfig($key, 'must be a plain decimal string');
        }

        try {
            return BigDecimal::of($value)->toScale(max(0, $scale));
        } catch (RoundingNecessaryException) {
            throw new InvalidAttributeConfig($key, "has more than {$scale} decimal places");
        } catch (MathException) {
            throw new InvalidAttributeConfig($key, 'must be a plain decimal string');
        }
    }
}
