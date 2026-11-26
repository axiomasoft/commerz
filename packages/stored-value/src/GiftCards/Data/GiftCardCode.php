<?php

declare(strict_types=1);

namespace Commerz\StoredValue\GiftCards\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/**
 * Canonical gift-card code normalization, generation, and SHA-256 hashing.
 *
 * Plaintext codes are never persisted; only `hash($code)` (64-char SHA-256 hex) and `last4($code)` are stored.
 */
#[Api(since: '0.1')]
final readonly class GiftCardCode
{
    public static function normalize(string $raw): string
    {
        $clean = strtoupper((string) preg_replace('/[\s-]+/', '', trim($raw)));

        if ($clean === '' || strlen($clean) < 8 || strlen($clean) > 64 || preg_match('/\A[A-Z0-9]+\z/', $clean) !== 1) {
            throw new InvalidCommand('code', 'gift card code must be 8..64 alphanumeric characters', 'stored_value.invalid_code');
        }

        return $clean;
    }

    public static function hash(string $plainCode): string
    {
        return hash('sha256', self::normalize($plainCode));
    }

    public static function last4(string $plainCode): string
    {
        return substr(self::normalize($plainCode), -4);
    }

    public static function generate(int $length = 16, string $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ', string $prefix = 'GC'): string
    {
        if ($length < 8 || $length > 32) {
            throw new InvalidCommand('length', 'generated gift card length must be 8..32', 'stored_value.invalid_code');
        }

        $alphabetLength = strlen($alphabet);

        if ($alphabetLength < 16) {
            throw new InvalidCommand('alphabet', 'gift card alphabet must have at least 16 characters', 'stored_value.invalid_code');
        }

        $body = '';

        for ($i = 0; $i < $length; $i++) {
            $body .= $alphabet[random_int(0, $alphabetLength - 1)];
        }

        $chunks = str_split($body, 4);
        $formatted = implode('-', $chunks);

        return $prefix !== '' ? $prefix.'-'.$formatted : $formatted;
    }
}
