<?php

declare(strict_types=1);

namespace Commerz\Catalog\Tests\Support;

use Commerz\Catalog\Attributes\Contracts\AttributeType;
use Commerz\Catalog\Attributes\Data\AttributeChoiceId;
use Commerz\Catalog\Attributes\Data\AttributeChoiceView;
use Commerz\Catalog\Attributes\Data\AttributeDefinition;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeConfig;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Commerz\Measures\LocalizedText;

/** Builders and assertions shared by the attribute type tests. */
final class Attrs
{
    /**
     * @param  array<string, mixed>  $config  raw config, canonicalized by the type
     * @param  list<string>  $choiceCodes  in position order
     */
    public static function definition(AttributeType $type, array $config = [], array $choiceCodes = []): AttributeDefinition
    {
        $choices = [];

        foreach ($choiceCodes as $position => $code) {
            $choices[] = new AttributeChoiceView(AttributeChoiceId::generate(), $code, LocalizedText::of('en', ucfirst($code)), $position);
        }

        return new AttributeDefinition('attr', $type->config($config), $choices);
    }

    public static function rejects(AttributeType $type, mixed $input, AttributeDefinition $definition): bool
    {
        try {
            $type->normalize($input, $definition);
        } catch (InvalidAttributeValue) {
            return true;
        }

        return false;
    }

    /** @param  array<string, mixed>  $config */
    public static function rejectsConfig(AttributeType $type, array $config): bool
    {
        try {
            $type->config($config);
        } catch (InvalidAttributeConfig) {
            return true;
        }

        return false;
    }
}
