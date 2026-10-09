<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Internal\Types;

use Commerz\Catalog\Attributes\Contracts\AttributeType;
use Commerz\Catalog\Attributes\Data\AttributeDefinition;
use Commerz\Catalog\Attributes\Data\IndexFact;
use Commerz\Catalog\Attributes\Enums\FilterOperator;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Commerz\Catalog\Attributes\Internal\ConfigReader;
use DateTimeInterface;

final readonly class DateType implements AttributeType
{
    public function key(): string
    {
        return 'date';
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

    public function normalize(mixed $input, AttributeDefinition $definition): string
    {
        if ($input instanceof DateTimeInterface) {
            return $input->format('Y-m-d');
        }

        if (is_string($input) && preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $input, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $input;
        }

        throw new InvalidAttributeValue($definition->code, 'expected a date as Y-m-d');
    }

    public function index(string|int|bool|array $value, AttributeDefinition $definition): array
    {
        return is_string($value) ? [IndexFact::day($value)] : [];
    }

    public function operators(): array
    {
        return [FilterOperator::Equals, FilterOperator::Range];
    }
}
