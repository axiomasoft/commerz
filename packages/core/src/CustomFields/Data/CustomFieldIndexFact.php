<?php

declare(strict_types=1);

namespace Commerz\CustomFields\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/**
 * One typed projection fact for `cmz_custom_field_index`: exactly one of `number`, `flag`, `day`, or `term` is set.
 */
#[Api(since: '0.1')]
final readonly class CustomFieldIndexFact
{
    private function __construct(
        public ?string $number = null,
        public ?bool $flag = null,
        public ?string $day = null,
        public ?string $term = null,
    ) {}

    public static function number(string $decimal): self
    {
        if (preg_match('/\A-?\d+(\.\d+)?\z/', $decimal) !== 1) {
            throw new InvalidCommand('number', "'{$decimal}' is not a plain decimal number", 'custom_fields.invalid_fact');
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
            throw new InvalidCommand('day', "'{$ymd}' is not a Y-m-d date", 'custom_fields.invalid_fact');
        }

        return new self(day: $ymd);
    }

    public static function term(string $text): self
    {
        return new self(term: mb_substr($text, 0, 255, 'UTF-8'));
    }
}
