<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Internal\Types;

use Commerz\Catalog\Attributes\Contracts\AttributeType;
use Commerz\Catalog\Attributes\Data\AttributeDefinition;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Commerz\Catalog\Attributes\Internal\ConfigReader;
use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Packman\Attributes\Internal;

#[Internal]
final readonly class LocalizedTextType implements AttributeType
{
    public function key(): string
    {
        return 'localized_text';
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

        return ['maxLength' => ConfigReader::int($config, 'maxLength', 1, 100000, 10000)];
    }

    public function normalize(mixed $input, AttributeDefinition $definition): array
    {
        $texts = is_array($input) ? $this->texts($input) : null;

        try {
            $text = match (true) {
                $input instanceof LocalizedText => $input,
                $texts !== null && $texts !== [] => new LocalizedText($texts),
                default => throw new InvalidAttributeValue($definition->code, 'expected texts by locale'),
            };
        } catch (InvalidCommand $e) {
            throw new InvalidAttributeValue($definition->code, $e->reason);
        }

        $max = $definition->config['maxLength'] ?? 10000;
        $max = is_int($max) ? $max : 10000;
        $texts = $text->texts;

        foreach ($texts as $locale => $value) {
            if (! mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $max) {
                throw new InvalidAttributeValue($definition->code, "text for '{$locale}' is not valid or longer than {$max} characters");
            }
        }

        ksort($texts);

        return $texts;
    }

    public function index(string|int|bool|array $value, AttributeDefinition $definition): array
    {
        return [];
    }

    public function operators(): array
    {
        return [];
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string>|null
     */
    private function texts(array $values): ?array
    {
        $texts = [];

        foreach ($values as $locale => $value) {
            if (! is_string($value)) {
                return null;
            }

            $texts[$locale] = $value;
        }

        return $texts;
    }
}
