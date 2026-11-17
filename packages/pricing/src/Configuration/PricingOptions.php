<?php

declare(strict_types=1);

namespace Commerz\Pricing\Configuration;

use Commerz\Money\RoundingMode;
use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable compiled configuration snapshot for `axiomasoft/commerz-pricing`.
 */
#[Api(since: '0.1')]
final readonly class PricingOptions implements OptionsFactory
{
    /** @var list<string> */
    private const array TOP_KEYS = [
        'fallback_to_sellable',
        'tax',
    ];

    /** @var list<string> */
    private const array TAX_KEYS = [
        'calculator',
        'default_class',
        'delivery_class',
        'default_zone',
        'rounding',
    ];

    public function __construct(
        public bool $fallbackToSellable = true,
        public string $taxCalculator = 'rates',
        public string $defaultTaxClass = 'standard',
        public ?string $deliveryTaxClass = 'standard',
        public ?string $defaultTaxZone = null,
        public RoundingMode $taxRounding = RoundingMode::HalfUp,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'commerz-pricing'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, self::TOP_KEYS, $path);

        $fallback = $config['fallback_to_sellable'] ?? true;

        if (! is_bool($fallback)) {
            $validator->add($path.'.fallback_to_sellable', 'expected true or false');
            $fallback = true;
        }

        $tax = $config['tax'] ?? [];

        if (! is_array($tax)) {
            $validator->add($path.'.tax', 'expected an array');
            $tax = [];
        } else {
            $validator->rejectUnknownKeys($tax, self::TAX_KEYS, $path.'.tax');
        }

        $calculator = $tax['calculator'] ?? 'rates';

        if (! is_string($calculator) || preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)?\z/', $calculator) !== 1) {
            $validator->add($path.'.tax.calculator', "expected a calculator key such as 'rates' or 'vendor.name'");
            $calculator = 'rates';
        }

        $rounding = $tax['rounding'] ?? RoundingMode::HalfUp;
        $roundingEnum = $rounding instanceof RoundingMode ? $rounding : (is_string($rounding) ? RoundingMode::tryFrom($rounding) : null);

        if ($roundingEnum === null) {
            $validator->add($path.'.tax.rounding', 'expected a rounding mode such as half_up or half_even');
            $roundingEnum = RoundingMode::HalfUp;
        }

        $defaultClass = self::code(
            $validator,
            array_key_exists('default_class', $tax) ? $tax['default_class'] : 'standard',
            $path.'.tax.default_class',
        ) ?? 'standard';

        $deliveryRaw = array_key_exists('delivery_class', $tax) ? $tax['delivery_class'] : 'standard';
        $deliveryClass = $deliveryRaw === null ? null : self::code($validator, $deliveryRaw, $path.'.tax.delivery_class');

        $zoneRaw = $tax['default_zone'] ?? null;
        $defaultZone = $zoneRaw === null ? null : self::code($validator, $zoneRaw, $path.'.tax.default_zone');

        $validator->failIfAny();

        return new self(
            $fallback,
            $calculator,
            $defaultClass,
            $deliveryClass,
            $defaultZone,
            $roundingEnum,
        );
    }

    private static function code(ConfigurationValidator $validator, mixed $value, string $path): ?string
    {
        if (! is_string($value) || preg_match('/\A[a-z0-9][a-z0-9_.\-]{0,63}\z/', $value) !== 1) {
            $validator->add($path, 'expected a code matching [a-z0-9][a-z0-9_.-]{0,63}');

            return null;
        }

        return $value;
    }
}
