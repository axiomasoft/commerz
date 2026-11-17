<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Data;

use InvalidArgumentException;
use Packman\Attributes\Api;

/** One row of the filter projection: exactly one of the fields is set. */
#[Api(since: '0.1')]
final readonly class IndexFact
{
    private function __construct(
        public ?AttributeChoiceId $choice = null,
        public ?string $number = null,
        public ?bool $flag = null,
        public ?string $day = null,
        public ?string $term = null,
    ) {}

    public static function choice(AttributeChoiceId $id): self
    {
        return new self(choice: $id);
    }

    /** @param  numeric-string|string  $decimal */
    public static function number(string $decimal): self
    {
        if (preg_match('/\A-?\d+(\.\d+)?\z/', $decimal) !== 1) {
            throw new InvalidArgumentException("'{$decimal}' is not a plain decimal number.");
        }

        return new self(number: $decimal);
    }

    public static function flag(bool $value): self
    {
        return new self(flag: $value);
    }

    public static function day(string $ymd): self
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $ymd) !== 1) {
            throw new InvalidArgumentException("'{$ymd}' is not a Y-m-d date.");
        }

        return new self(day: $ymd);
    }

    public static function term(string $text): self
    {
        return new self(term: mb_substr($text, 0, 255));
    }
}
