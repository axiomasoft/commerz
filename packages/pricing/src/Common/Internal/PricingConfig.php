<?php

declare(strict_types=1);

namespace Commerz\Pricing\Common\Internal;

use Commerz\Errors\InvalidConfiguration;
use Commerz\Money\RoundingMode;

/** The only reader of `config('commerz-pricing.*')`. */
final readonly class PricingConfig
{
    public function __construct(
        public bool $fallbackToSellable,
        public string $taxCalculator,
        public string $defaultTaxClass,
        public ?string $deliveryTaxClass,
        public ?string $defaultTaxZone,
        public RoundingMode $taxRounding,
    ) {}

    /** @param  array<array-key, mixed>  $config */
    public static function fromArray(array $config, string $path = 'commerz-pricing'): self
    {
        $fallback = $config['fallback_to_sellable'] ?? true;

        if (! is_bool($fallback)) {
            throw new InvalidConfiguration("{$path}.fallback_to_sellable", 'expected true or false');
        }

        $tax = $config['tax'] ?? [];

        if (! is_array($tax)) {
            throw new InvalidConfiguration("{$path}.tax", 'expected an array');
        }

        $calculator = $tax['calculator'] ?? 'rates';

        if (! is_string($calculator) || preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)?\z/', $calculator) !== 1) {
            throw new InvalidConfiguration("{$path}.tax.calculator", "expected a calculator key such as 'rates' or 'vendor.name'");
        }

        $rounding = $tax['rounding'] ?? RoundingMode::HalfUp;
        $rounding = $rounding instanceof RoundingMode ? $rounding : (is_string($rounding) ? RoundingMode::tryFrom($rounding) : null);

        if ($rounding === null) {
            throw new InvalidConfiguration("{$path}.tax.rounding", 'expected a rounding mode such as half_up or half_even');
        }

        return new self(
            $fallback,
            $calculator,
            self::code(array_key_exists('default_class', $tax) ? $tax['default_class'] : 'standard', "{$path}.tax.default_class"),
            self::nullableCode(array_key_exists('delivery_class', $tax) ? $tax['delivery_class'] : 'standard', "{$path}.tax.delivery_class"),
            self::nullableCode($tax['default_zone'] ?? null, "{$path}.tax.default_zone"),
            $rounding,
        );
    }

    private static function code(mixed $value, string $path): string
    {
        if (! is_string($value) || preg_match('/\A[a-z0-9][a-z0-9_.\-]{0,63}\z/', $value) !== 1) {
            throw new InvalidConfiguration($path, 'expected a code matching [a-z0-9][a-z0-9_.-]{0,63}');
        }

        return $value;
    }

    private static function nullableCode(mixed $value, string $path): ?string
    {
        return $value === null ? null : self::code($value, $path);
    }
}
