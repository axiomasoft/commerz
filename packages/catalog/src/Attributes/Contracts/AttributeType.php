<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Contracts;

use Commerz\Catalog\Attributes\Data\AttributeDefinition;
use Commerz\Catalog\Attributes\Data\IndexFact;
use Commerz\Catalog\Attributes\Enums\FilterOperator;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeConfig;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** A data type of attribute values, contributed under kind `attribute_type`; stateless, pure, no IO. */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface AttributeType
{
    public const string KIND = 'attribute_type';

    /** Key stored with every definition: 'decimal', 'acme.color_swatch'. */
    public function key(): string;

    /** Version of the config format this type writes; stored as attributes.config_version. */
    public function configVersion(): int;

    /** Whether definitions of this type own a list of choices. */
    public function usesChoices(): bool;

    /**
     * Validates and canonicalizes a definition config (sorted keys, defaults filled).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed> JSON-safe, no floats
     *
     * @throws InvalidAttributeConfig
     */
    public function config(array $config): array;

    /**
     * Input (from a command, an import or its own stored form) to the canonical stored value. Idempotent:
     * normalize(normalize(x)) === normalize(x). Never returns null or a float.
     *
     * @return string|int|bool|array<array-key, mixed>
     *
     * @throws InvalidAttributeValue
     */
    public function normalize(mixed $input, AttributeDefinition $definition): string|int|bool|array;

    /**
     * Facts of a canonical value for the filter projection.
     *
     * @param  string|int|bool|array<array-key, mixed>  $value
     * @return list<IndexFact>
     */
    public function index(string|int|bool|array $value, AttributeDefinition $definition): array;

    /** @return list<FilterOperator> operators a filterable attribute of this type supports; [] = never filterable */
    public function operators(): array;
}
