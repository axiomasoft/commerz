<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Internal\Types;

use Commerz\Catalog\Attributes\Contracts\AttributeType;
use Commerz\Catalog\Attributes\Data\AttributeDefinition;
use Commerz\Catalog\Attributes\Data\IndexFact;
use Commerz\Catalog\Attributes\Enums\FilterOperator;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Commerz\Catalog\Attributes\Internal\ConfigReader;

final readonly class TextType implements AttributeType
{
    public function key(): string
    {
        return 'text';
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
        ConfigReader::only($config, ['maxLength']);

        return ['maxLength' => ConfigReader::int($config, 'maxLength', 1, 10000, 255)];
    }

    public function normalize(mixed $input, AttributeDefinition $definition): string
    {
        if (! is_string($input) || trim($input) === '' || ! mb_check_encoding($input, 'UTF-8')) {
            throw new InvalidAttributeValue($definition->code, 'expected a non-empty text');
        }

        $max = $definition->config['maxLength'] ?? 255;

        if (is_int($max) && mb_strlen($input) > $max) {
            throw new InvalidAttributeValue($definition->code, "longer than {$max} characters");
        }

        return $input;
    }

    public function index(string|int|bool|array $value, AttributeDefinition $definition): array
    {
        return is_string($value) ? [IndexFact::term($value)] : [];
    }

    public function operators(): array
    {
        return [FilterOperator::Equals, FilterOperator::In];
    }
}
