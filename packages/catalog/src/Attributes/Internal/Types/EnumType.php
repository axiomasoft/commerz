<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Internal\Types;

use Commerz\Catalog\Attributes\Contracts\AttributeType;
use Commerz\Catalog\Attributes\Data\AttributeDefinition;
use Commerz\Catalog\Attributes\Data\IndexFact;
use Commerz\Catalog\Attributes\Enums\FilterOperator;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Commerz\Catalog\Attributes\Internal\ConfigReader;

final readonly class EnumType implements AttributeType
{
    public function key(): string
    {
        return 'enum';
    }

    public function configVersion(): int
    {
        return 1;
    }

    public function usesChoices(): bool
    {
        return true;
    }

    public function config(array $config): array
    {
        ConfigReader::only($config, []);

        return [];
    }

    public function normalize(mixed $input, AttributeDefinition $definition): string
    {
        if (! is_string($input) || $definition->choice($input) === null) {
            throw new InvalidAttributeValue($definition->code, 'expected the code of one of the choices');
        }

        return $input;
    }

    public function index(string|int|bool|array $value, AttributeDefinition $definition): array
    {
        $choice = is_string($value) ? $definition->choice($value) : null;

        return $choice === null ? [] : [IndexFact::choice($choice->id)];
    }

    public function operators(): array
    {
        return [FilterOperator::Equals, FilterOperator::In];
    }
}
