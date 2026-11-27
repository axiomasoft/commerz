<?php

declare(strict_types=1);

namespace Commerz\Ports\Privacy\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SensitiveParameter;

/**
 * Host-agnostic PII encryption and deterministic blind-index port. Allows a host application to protect sensitive
 * personal data at rest with its own KMS or key-rotation vault while keeping core commerce storage transport-neutral.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface PiiCipherPort
{
    /** Encrypts plaintext scoped to `$purpose` (e.g. `'customer.email'`, `'order.shipping_address'`). */
    public function encrypt(#[SensitiveParameter] string $plaintext, string $purpose = 'default'): string;

    /** Decrypts a previously encrypted value scoped to `$purpose`. */
    public function decrypt(#[SensitiveParameter] string $ciphertext, string $purpose = 'default'): string;

    /** Computes a deterministic lowercase 64-hex digest for equality lookups without exposing plaintext. */
    public function blindIndex(#[SensitiveParameter] string $value, string $purpose = 'default'): string;

    /** Reports whether `$value` carries an encrypted envelope produced by this cipher. */
    public function isEncrypted(string $value): bool;
}
