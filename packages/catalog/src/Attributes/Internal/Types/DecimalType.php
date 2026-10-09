<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Internal\Types;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Commerz\Catalog\Attributes\Contracts\AttributeType;
use Commerz\Catalog\Attributes\Data\AttributeDefinition;
use Commerz\Catalog\Attributes\Data\IndexFact;
use Commerz\Catalog\Attributes\Enums\FilterOperator;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeConfig;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Commerz\Catalog\Attributes\Internal\ConfigReader;

final readonly class DecimalType implements AttributeType
{
    public function key(): string
    {
        return 'decimal';
    }

    public function configVersion(): int
    {
        return 1;
    }

    public function usesChoices(): bool
    {
        return false;
    }

    public function config(array $config): array
    {
        ConfigReader::only($config, ['scale', 'min', 'max']);

        $scale = ConfigReader::int($config, 'scale', 0, 12, 2) ?? 2;
        $min = ConfigReader::bound($config, 'min', $scale);
        $max = ConfigReader::bound($config, 'max', $scale);

        if ($min !== null && $max !== null && $min->isGreaterThan($max)) {
            throw new InvalidAttributeConfig('min', 'must not exceed max');
        }

        $result = ['scale' => $scale];

        if ($max !== null) {
            $result = ['max' => (string) $max, ...$result];
        }

        if ($min !== null) {
            $result = [...$result, 'min' => (string) $min];
        }

        ksort($result);

        return $result;
    }

    public function normalize(mixed $input, AttributeDefinition $definition): string
    {
        $scale = $definition->config['scale'] ?? 2;
        $scale = is_int($scale) ? $scale : 2;

        $decimal = self::decimal($input, $scale, $definition->code);

        foreach (['min' => -1, 'max' => 1] as $bound => $direction) {
            $limit = $definition->config[$bound] ?? null;

            if (is_string($limit) && $decimal->compareTo($limit) === $direction) {
                throw new InvalidAttributeValue($definition->code, 'outside the allowed range');
            }
        }

        return (string) $decimal;
    }

    public function index(string|int|bool|array $value, AttributeDefinition $definition): array
    {
        return is_string($value) ? [IndexFact::number($value)] : [];
    }

    public function operators(): array
    {
        return [FilterOperator::Equals, FilterOperator::In, FilterOperator::Range];
    }

    /** A plain int or decimal string with no more than `$scale` places, as a decimal of exactly that scale. */
    public static function decimal(mixed $input, int $scale, string $attribute): BigDecimal
    {
        if (is_int($input)) {
            $input = (string) $input;
        }

        if (! is_string($input) || preg_match('/\A-?\d{1,30}(\.\d{1,30})?\z/', $input) !== 1) {
            throw new InvalidAttributeValue($attribute, 'expected a decimal number as a string or an integer');
        }

        try {
            return BigDecimal::of($input)->toScale(max(0, $scale));
        } catch (RoundingNecessaryException) {
            throw new InvalidAttributeValue($attribute, "more than {$scale} decimal places");
        }
    }
}
