<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Internal;

/**
 * The stored identity of a combination (CAT-01): `{optionId}={valueId}` pairs sorted by option id, joined by `;`.
 * The empty combination (a product without axes) is `''`. Ids, not codes: codes are labels, ids never change.
 */
final class OptionSignature
{
    /** @param  array<string, string>  $values  option id => option value id */
    public static function of(array $values): string
    {
        ksort($values, SORT_STRING);

        return implode(';', array_map(static fn (string $option, string $value): string => "{$option}={$value}", array_keys($values), $values));
    }
}
