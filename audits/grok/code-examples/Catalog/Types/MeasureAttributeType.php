<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Internal\Types;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Commerz\Catalog\Attributes\Contracts\AttributeConfig;
use Commerz\Catalog\Attributes\Contracts\AttributeType;
use Commerz\Catalog\Attributes\Data\AttributeCapabilities;
use Commerz\Catalog\Attributes\Data\AttributeIndexRow;
use Commerz\Catalog\Attributes\Data\AttributeValue;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Commerz\Catalog\Attributes\Enums\MeasureUnit;
use Override;

/** Пример встроенного типа `measure`: «мощность 1.5 kW» хранится и фильтруется в канонической единице (W). */
final readonly class MeasureConfig implements AttributeConfig
{
    public function __construct(public MeasureUnit $displayUnit, public int $scale, public ?string $min, public ?string $max) {}

    #[Override]
    public static function fromArray(array $raw): static
    {
        $unit = is_string($raw['unit'] ?? null) ? MeasureUnit::tryFrom($raw['unit']) : null;
        $scale = $raw['scale'] ?? 3;
        $min = $raw['min'] ?? null;
        $max = $raw['max'] ?? null;

        if ($unit === null || ! is_int($scale) || $scale < 0 || $scale > 6 || ! (is_string($min) || $min === null) || ! (is_string($max) || $max === null)) {
            throw InvalidAttributeValue::because('measure config: {unit: MeasureUnit, scale: 0..6, min?: decimal string, max?: decimal string}');
        }

        return new static($unit, $scale, $min, $max);
    }
}

final readonly class MeasureValue implements AttributeValue
{
    /** @param string $canonical десятичная строка в канонической единице измерения */
    public function __construct(public string $canonical, public MeasureUnit $enteredUnit) {}
}

final readonly class MeasureAttributeType implements AttributeType
{
    #[Override]
    public function key(): string
    {
        return 'measure';
    }

    #[Override]
    public function configClass(): string
    {
        return MeasureConfig::class;
    }

    /** Принимает '1.5 kW', ['value' => '1500', 'unit' => 'W']; float отвергается — точность важнее удобства. */
    #[Override]
    public function normalize(mixed $raw, AttributeConfig $config): AttributeValue
    {
        assert($config instanceof MeasureConfig);

        [$number, $unitCode] = match (true) {
            is_string($raw) && preg_match('/\A\s*(-?\d+(?:\.\d+)?)\s*([A-Za-z]+)\s*\z/D', $raw, $m) === 1 => [$m[1], $m[2]],
            is_array($raw) && is_string($raw['value'] ?? null) && is_string($raw['unit'] ?? null) => [$raw['value'], $raw['unit']],
            default => throw InvalidAttributeValue::because('expected "<number> <unit>" or {value, unit}'),
        };

        $unit = MeasureUnit::tryFrom($unitCode) ?? throw InvalidAttributeValue::because("unknown unit [{$unitCode}]");
        if ($unit->dimension() !== $config->displayUnit->dimension()) {
            throw InvalidAttributeValue::because("unit [{$unit->value}] is not a {$config->displayUnit->dimension()}");
        }

        try {
            $canonical = BigDecimal::of($number)->multipliedBy($unit->toCanonicalFactor())->toScale($config->scale, RoundingMode::HALF_UP);
        } catch (MathException) {
            throw InvalidAttributeValue::because("[{$number}] is not a decimal");
        }

        if ($config->min !== null && $canonical->isLessThan($config->min)) {
            throw InvalidAttributeValue::because("below minimum {$config->min}");
        }
        if ($config->max !== null && $canonical->isGreaterThan($config->max)) {
            throw InvalidAttributeValue::because("above maximum {$config->max}");
        }

        return new MeasureValue((string) $canonical, $unit);
    }

    #[Override]
    public function toDocument(AttributeValue $value): array
    {
        assert($value instanceof MeasureValue);

        return ['value' => $value->canonical, 'unit' => $value->enteredUnit->value];
    }

    #[Override]
    public function fromDocument(string|int|bool|array $stored, AttributeConfig $config): AttributeValue
    {
        if (! is_array($stored) || ! is_string($stored['value'] ?? null) || ! is_string($stored['unit'] ?? null)) {
            throw InvalidAttributeValue::because('corrupted measure document');
        }

        return new MeasureValue($stored['value'], MeasureUnit::from($stored['unit']));
    }

    #[Override]
    public function project(AttributeValue $value): array
    {
        assert($value instanceof MeasureValue);

        return [AttributeIndexRow::num($value->canonical)];
    }

    #[Override]
    public function capabilities(): AttributeCapabilities
    {
        return new AttributeCapabilities(equals: true, range: true, contains: false, sortable: true);
    }
}
