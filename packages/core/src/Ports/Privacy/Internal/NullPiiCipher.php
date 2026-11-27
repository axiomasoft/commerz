<?php

declare(strict_types=1);

namespace Commerz\Ports\Privacy\Internal;

use Commerz\Errors\InvalidCommand;
use Commerz\Ports\Privacy\Contracts\PiiCipherPort;
use Override;
use Packman\Attributes\Internal;
use SensitiveParameter;

/**
 * No-op passthrough default when the host does not bind a `PiiCipherPort`: stores values unencrypted and computes
 * deterministic SHA-256 blind indexes scoped by purpose.
 */
#[Internal]
final readonly class NullPiiCipher implements PiiCipherPort
{
    #[Override]
    public function encrypt(#[SensitiveParameter] string $plaintext, string $purpose = 'default'): string
    {
        self::assertPurpose($purpose);

        return $plaintext;
    }

    #[Override]
    public function decrypt(#[SensitiveParameter] string $ciphertext, string $purpose = 'default'): string
    {
        self::assertPurpose($purpose);

        return $ciphertext;
    }

    #[Override]
    public function blindIndex(#[SensitiveParameter] string $value, string $purpose = 'default'): string
    {
        self::assertPurpose($purpose);

        return hash('sha256', $purpose."\0".mb_strtolower(trim($value), 'UTF-8'));
    }

    #[Override]
    public function isEncrypted(string $value): bool
    {
        return false;
    }

    private static function assertPurpose(string $purpose): void
    {
        if (preg_match('/\A[a-z0-9_.\-]{1,64}\z/', $purpose) !== 1) {
            throw new InvalidCommand('purpose', 'must match [a-z0-9_.-]{1,64}', 'privacy.invalid_purpose');
        }
    }
}
