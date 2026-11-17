<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Internal\Types;

use Commerz\Catalog\Attributes\Contracts\AttributeType;
use Commerz\Catalog\Attributes\Data\AttributeDefinition;
use Commerz\Catalog\Attributes\Data\IndexFact;
use Commerz\Catalog\Attributes\Enums\FilterOperator;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Commerz\Catalog\Attributes\Internal\ConfigReader;
use Packman\Attributes\Internal;

#[Internal]
final readonly class BooleanType implements AttributeType
{
    public function key(): string
    {
        return 'boolean';
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
        ConfigReader::only($config, []);

        return [];
    }

    public function normalize(mixed $input, AttributeDefinition $definition): bool
    {
        return is_bool($input) ? $input : throw new InvalidAttributeValue($definition->code, 'expected true or false');
    }

    public function index(string|int|bool|array $value, AttributeDefinition $definition): array
    {
        return is_bool($value) ? [IndexFact::flag($value)] : [];
    }

    public function operators(): array
    {
        return [FilterOperator::Equals];
    }
}
