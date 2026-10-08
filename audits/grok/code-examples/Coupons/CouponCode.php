<?php

declare(strict_types=1);

namespace Commerz\Coupons\Data;

use Commerz\Attributes\Api;
use Commerz\Coupons\Exceptions\InvalidCouponCode;

/** Нормализованный код: trim → upper → без пробелов и '-' → ^[A-Z0-9]{3,64}$ (тот же CHECK в БД). */
#[Api(since: '1.0')]
final readonly class CouponCode
{
    /** Алфавит генерации без 0 O 1 I L — коды диктуют по телефону и печатают на чеках. */
    public const string ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private function __construct(public string $value) {}

    public static function normalize(string $input): self
    {
        $value = strtoupper(str_replace([' ', '-', "\t"], '', trim($input)));

        return preg_match('/\A[A-Z0-9]{3,64}\z/D', $value) === 1
            ? new self($value)
            : throw InvalidCouponCode::format($input);
    }

    public static function tryNormalize(string $input): ?self
    {
        try {
            return self::normalize($input);
        } catch (InvalidCouponCode) {
            return null;
        }
    }

    public static function generate(string $prefix, int $length): self
    {
        $alphabetSize = strlen(self::ALPHABET);
        $body = '';
        for ($i = 0; $i < $length; $i++) {
            $body .= self::ALPHABET[random_int(0, $alphabetSize - 1)];
        }

        return self::normalize($prefix.$body);
    }
}
