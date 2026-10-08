<?php

declare(strict_types=1);

namespace Commerz\Identity;

use Commerz\Attributes\Api;
use LogicException;

/**
 * Time-ordered UUIDv7 generator (RFC 9562, method 1: a 12-bit counter keeps ids from one process
 * strictly increasing inside a millisecond). Tests pin ids with `fixed()`.
 */
#[Api(since: '0.1')]
final class Uuid7
{
    /** @var list<string>|null */
    private static ?array $fixed = null;

    private static int $lastMs = 0;

    private static int $counter = 0;

    /** Makes `generate()` return the given ids in order; running out is a test error. */
    public static function fixed(string ...$uuids): void
    {
        self::$fixed = array_values($uuids);
    }

    public static function reset(): void
    {
        self::$fixed = null;
    }

    public static function generate(): string
    {
        if (self::$fixed !== null) {
            return array_shift(self::$fixed) ?? throw new LogicException('Uuid7::fixed() ran out of ids.');
        }

        $ms = (int) (microtime(true) * 1000);

        if ($ms > self::$lastMs) {
            self::$lastMs = $ms;
            self::$counter = random_int(0, 0x7FF);
        } else {
            self::$counter++;

            if (self::$counter > 0xFFF) {
                self::$lastMs++;
                self::$counter = random_int(0, 0x7FF);
            }
        }

        $tail = random_bytes(8);
        $tail[0] = chr((ord($tail[0]) & 0x3F) | 0x80);

        $hex = bin2hex(substr(pack('J', self::$lastMs), 2)).dechex(0x7000 | self::$counter).bin2hex($tail);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }
}
