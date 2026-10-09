<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Internal\Types;

use Commerz\Catalog\Attributes\Contracts\AttributeType;
use Commerz\Catalog\Attributes\Data\AttributeDefinition;
use Commerz\Catalog\Attributes\Data\IndexFact;
use Commerz\Catalog\Attributes\Enums\FilterOperator;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeConfig;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Commerz\Catalog\Attributes\Internal\ConfigReader;
use Commerz\Errors\InvalidCommand;
use Commerz\Measures\Measure;
use Commerz\Measures\Unit;

final readonly class MeasureType implements AttributeType
{
    public function key(): string
    {
        return 'measure';
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
        ConfigReader::only($config, ['unit', 'scale']);

        $unit = $config['unit'] ?? null;

        if (! is_string($unit)) {
            throw new InvalidAttributeConfig('unit', 'is required');
        }

        try {
            Unit::assert($unit);
        } catch (InvalidCommand $e) {
            throw new InvalidAttributeConfig('unit', $e->reason);
        }

        return ['scale' => ConfigReader::int($config, 'scale', 0, 12, 3), 'unit' => $unit];
    }

    public function normalize(mixed $input, AttributeDefinition $definition): array
    {
        [$value, $unit] = match (true) {
            $input instanceof Measure => [$input->value, $input->unit],
            is_array($input) && array_keys($input) !== [] && array_diff(array_keys($input), ['unit', 'value']) === [] && count($input) === 2 => [$input['value'], $input['unit']],
            default => throw new InvalidAttributeValue($definition->code, 'expected a measure with value and unit'),
        };

        if ($unit !== ($definition->config['unit'] ?? null)) {
            throw new InvalidAttributeValue($definition->code, 'the unit differs from the one the attribute is measured in');
        }

        $scale = $definition->config['scale'] ?? 3;

        return [
            'unit' => $unit,
            'value' => (string) DecimalType::decimal($value, is_int($scale) ? $scale : 3, $definition->code),
        ];
    }

    public function index(string|int|bool|array $value, AttributeDefinition $definition): array
    {
        $number = is_array($value) ? ($value['value'] ?? null) : null;

        return is_string($number) ? [IndexFact::number($number)] : [];
    }

    public function operators(): array
    {
        return [FilterOperator::Equals, FilterOperator::Range];
    }
}
