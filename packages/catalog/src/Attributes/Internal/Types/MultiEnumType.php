<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Internal\Types;

use Commerz\Catalog\Attributes\Contracts\AttributeType;
use Commerz\Catalog\Attributes\Data\AttributeChoiceView;
use Commerz\Catalog\Attributes\Data\AttributeDefinition;
use Commerz\Catalog\Attributes\Data\IndexFact;
use Commerz\Catalog\Attributes\Enums\FilterOperator;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Commerz\Catalog\Attributes\Internal\ConfigReader;

final readonly class MultiEnumType implements AttributeType
{
    public function key(): string
    {
        return 'multi_enum';
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

    public function normalize(mixed $input, AttributeDefinition $definition): array
    {
        if (! is_array($input) || $input === [] || ! array_is_list($input)) {
            throw new InvalidAttributeValue($definition->code, 'expected a non-empty list of choice codes');
        }

        /** @var array<string, AttributeChoiceView> $chosen */
        $chosen = [];

        foreach ($input as $code) {
            $choice = is_string($code) ? $definition->choice($code) : null;

            if ($choice === null) {
                throw new InvalidAttributeValue($definition->code, 'expected the code of one of the choices');
            }

            $chosen[$choice->code] = $choice;
        }

        $choices = array_values($chosen);
        usort($choices, static fn (AttributeChoiceView $a, AttributeChoiceView $b): int => [$a->position, $a->code] <=> [$b->position, $b->code]);

        return array_map(static fn (AttributeChoiceView $choice): string => $choice->code, $choices);
    }

    public function index(string|int|bool|array $value, AttributeDefinition $definition): array
    {
        $facts = [];

        foreach (is_array($value) ? $value : [] as $code) {
            $choice = is_string($code) ? $definition->choice($code) : null;

            if ($choice !== null) {
                $facts[] = IndexFact::choice($choice->id);
            }
        }

        return $facts;
    }

    public function operators(): array
    {
        return [FilterOperator::Equals, FilterOperator::In];
    }
}
