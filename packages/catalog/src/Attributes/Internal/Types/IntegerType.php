<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Internal\Types;

use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Commerz\Catalog\Attributes\Contracts\AttributeType;
use Commerz\Catalog\Attributes\Data\AttributeDefinition;
use Commerz\Catalog\Attributes\Data\IndexFact;
use Commerz\Catalog\Attributes\Enums\FilterOperator;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeConfig;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Commerz\Catalog\Attributes\Internal\ConfigReader;
use Packman\Attributes\Internal;

#[Internal]
final readonly class IntegerType implements AttributeType
{
    public function key(): string
    {
        return 'integer';
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
        ConfigReader::only($config, ['min', 'max']);

        $min = ConfigReader::int($config, 'min', PHP_INT_MIN, PHP_INT_MAX, null);
        $max = ConfigReader::int($config, 'max', PHP_INT_MIN, PHP_INT_MAX, null);

        if ($min !== null && $max !== null && $min > $max) {
            throw new InvalidAttributeConfig('min', 'must not exceed max');
        }

        return array_filter(['max' => $max, 'min' => $min], static fn (?int $bound): bool => $bound !== null);
    }

    public function normalize(mixed $input, AttributeDefinition $definition): int
    {
        $value = match (true) {
            is_int($input) => $input,
            is_string($input) && preg_match('/\A-?\d+\z/', $input) === 1 => $this->parse($input, $definition),
            default => throw new InvalidAttributeValue($definition->code, 'expected an integer'),
        };

        $min = $definition->config['min'] ?? null;
        $max = $definition->config['max'] ?? null;

        if ((is_int($min) && $value < $min) || (is_int($max) && $value > $max)) {
            throw new InvalidAttributeValue($definition->code, 'outside the allowed range');
        }

        return $value;
    }

    public function index(string|int|bool|array $value, AttributeDefinition $definition): array
    {
        return is_int($value) ? [IndexFact::number((string) $value)] : [];
    }

    public function operators(): array
    {
        return [FilterOperator::Equals, FilterOperator::In, FilterOperator::Range];
    }

    private function parse(string $input, AttributeDefinition $definition): int
    {
        try {
            return BigInteger::of($input)->toInt();
        } catch (MathException) {
            throw new InvalidAttributeValue($definition->code, 'does not fit a 64-bit integer');
        }
    }
}
