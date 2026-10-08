<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Contracts;

use Commerz\Attributes\Spi;
use Commerz\Attributes\SpiKind;
use Commerz\Catalog\Attributes\Data\AttributeCapabilities;
use Commerz\Catalog\Attributes\Data\AttributeIndexRow;
use Commerz\Catalog\Attributes\Data\AttributeValue;

/** Типизированный конфиг атрибута (min/max/scale/единица/выборы). */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface AttributeConfig
{
    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): static;
}

/**
 * Тип атрибута — плагин. Ключи встроенных типов совпадают с corex-custom-fields.
 * Значение в документе cmz_products.attributes / cmz_variants.attributes — то, что вернул toDocument().
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface AttributeType
{
    public function key(): string;

    /** @return class-string<AttributeConfig> */
    public function configClass(): string;

    /** Ввод админки/импорта → значение. Бросает InvalidAttributeValue с понятной причиной. */
    public function normalize(mixed $raw, AttributeConfig $config): AttributeValue;

    /** @return string|int|bool|array<array-key, mixed> без float и null */
    public function toDocument(AttributeValue $value): string|int|bool|array;

    /** @param string|int|bool|array<array-key, mixed> $stored */
    public function fromDocument(string|int|bool|array $stored, AttributeConfig $config): AttributeValue;

    /** @return list<AttributeIndexRow> строки проекции для фильтров; [] — не индексируется */
    public function project(AttributeValue $value): array;

    public function capabilities(): AttributeCapabilities;
}

namespace Commerz\Catalog\Attributes\Data;

use Commerz\Attributes\Api;

/** Маркер значения конкретного типа; каждый тип объявляет свой final readonly класс. */
#[Api(since: '1.0')]
interface AttributeValue {}

/** Ровно одно из полей заполнено — совпадает с колонками cmz_product_attribute_index. */
#[Api(since: '1.0')]
final readonly class AttributeIndexRow
{
    private function __construct(
        public ?string $choiceId = null,
        public ?string $num = null,      // numeric как строка — без float
        public ?bool $flag = null,
        public ?string $day = null,      // Y-m-d
        public ?string $term = null,
    ) {}

    public static function choice(string $id): self { return new self(choiceId: $id); }

    public static function num(string $decimal): self { return new self(num: $decimal); }

    public static function flag(bool $value): self { return new self(flag: $value); }

    public static function day(string $ymd): self { return new self(day: $ymd); }

    public static function term(string $value): self { return new self(term: mb_substr($value, 0, 255)); }
}

#[Api(since: '1.0')]
final readonly class AttributeCapabilities
{
    public function __construct(
        public bool $equals,
        public bool $range,
        public bool $contains,
        public bool $sortable,
        public bool $localizable = false,
    ) {}
}
