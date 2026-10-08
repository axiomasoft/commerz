<?php

declare(strict_types=1);

namespace Commerz\Stores\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;

/** Field checks shared by the store and channel commands. */
#[Api(since: '0.1')]
final class Codes
{
    public static function code(string $code): string
    {
        if (preg_match('/\A[a-z0-9][a-z0-9_.\-]{0,63}\z/', $code) !== 1) {
            throw new InvalidCommand('code', "'{$code}' must match [a-z0-9][a-z0-9_.-]{0,63}", 'stores.invalid_code');
        }

        return $code;
    }

    public static function name(string $name): string
    {
        if (trim($name) === '' || mb_strlen($name) > 191) {
            throw new InvalidCommand('name', 'must be 1 to 191 characters', 'stores.invalid_name');
        }

        return $name;
    }

    public static function locale(string $locale): string
    {
        if (preg_match('/\A[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*\z/', $locale) !== 1) {
            throw new InvalidCommand('locale', "'{$locale}' is not a locale tag", 'stores.invalid_locale');
        }

        return $locale;
    }

    public static function orderNumberPrefix(string $prefix): string
    {
        if (preg_match('/\A[A-Z0-9\-]{0,16}\z/', $prefix) !== 1) {
            throw new InvalidCommand('orderNumberPrefix', 'must be up to 16 characters of A-Z, 0-9 and "-"', 'stores.invalid_prefix');
        }

        return $prefix;
    }
}
