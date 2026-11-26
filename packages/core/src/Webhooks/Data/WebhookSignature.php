<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Webhooks\Exceptions\InvalidWebhookSignature;
use DateTimeImmutable;
use Packman\Attributes\Api;
use Stringable;

/**
 * Timestamped HMAC-SHA256 webhook signature (`t=<unix_ts>,v1=<hex>`) and payload-only HMAC-SHA256 helpers.
 * Verification is constant-time (`hash_equals`) and supports multiple active secrets for zero-downtime rotation.
 */
#[Api(since: '0.1')]
final readonly class WebhookSignature implements Stringable
{
    public const string SIGNATURE_HEADER = 'X-Commerz-Signature';

    public const string EVENT_ID_HEADER = 'X-Commerz-Event-Id';

    public const string EVENT_TYPE_HEADER = 'X-Commerz-Event-Type';

    public const string DELIVERY_ID_HEADER = 'X-Commerz-Delivery-Id';

    public const int DEFAULT_TOLERANCE_SECONDS = 300;

    /** @var non-empty-list<string> */
    public array $signatures;

    /**
     * @param  list<string>  $signatures  lowercase 64-hex HMAC-SHA256 digests
     */
    public function __construct(
        public int $timestamp,
        array $signatures,
    ) {
        if ($timestamp <= 0) {
            throw new InvalidCommand('timestamp', 'must be a positive Unix epoch timestamp', 'webhooks.invalid_signature_input');
        }

        if ($signatures === []) {
            throw new InvalidCommand('signatures', 'must contain at least one v1 hex digest', 'webhooks.invalid_signature_input');
        }

        $normalized = [];

        foreach ($signatures as $sig) {
            $lower = strtolower($sig);

            if (preg_match('/\A[0-9a-f]{64}\z/', $lower) !== 1) {
                throw new InvalidCommand('signatures', 'every digest must be 64 hexadecimal characters', 'webhooks.invalid_signature_input');
            }

            $normalized[] = $lower;
        }

        $this->signatures = array_values(array_unique($normalized));
    }

    public static function sign(string $rawBody, #[\SensitiveParameter] string $secret, int $timestamp): self
    {
        if ($secret === '') {
            throw new InvalidCommand('secret', 'must not be empty', 'webhooks.invalid_secret');
        }

        if ($timestamp <= 0) {
            throw new InvalidCommand('timestamp', 'must be a positive Unix epoch timestamp', 'webhooks.invalid_signature_input');
        }

        return new self($timestamp, [hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret)]);
    }

    public static function signPayload(string $rawBody, #[\SensitiveParameter] string $secret): string
    {
        if ($secret === '') {
            throw new InvalidCommand('secret', 'must not be empty', 'webhooks.invalid_secret');
        }

        return hash_hmac('sha256', $rawBody, $secret);
    }

    /** @throws InvalidWebhookSignature */
    public static function parse(string $header, string $source = 'webhook'): self
    {
        $trimmed = trim($header);

        if ($trimmed === '') {
            throw new InvalidWebhookSignature($source, 'signature header is empty');
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $trimmed) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                throw new InvalidWebhookSignature($source, 'malformed signature header segment');
            }

            [$key, $value] = $pair;

            if ($key === 't') {
                if ($timestamp !== null || preg_match('/\A[1-9][0-9]{0,15}\z/', $value) !== 1) {
                    throw new InvalidWebhookSignature($source, 'invalid signature timestamp');
                }

                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $lower = strtolower($value);

                if (preg_match('/\A[0-9a-f]{64}\z/', $lower) !== 1) {
                    throw new InvalidWebhookSignature($source, 'invalid v1 HMAC-SHA256 hex digest');
                }

                $signatures[] = $lower;
            }
        }

        if ($timestamp === null || $signatures === []) {
            throw new InvalidWebhookSignature($source, 'signature header must include t=<timestamp> and v1=<digest>');
        }

        return new self($timestamp, $signatures);
    }

    /**
     * @param  string|list<string>  $secrets
     */
    public static function verify(
        string $header,
        string $rawBody,
        #[\SensitiveParameter]
        string|array $secrets,
        DateTimeImmutable|int $now,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
    ): bool {
        try {
            self::assertValid($header, $rawBody, $secrets, $now, $toleranceSeconds);

            return true;
        } catch (InvalidWebhookSignature) {
            return false;
        }
    }

    /**
     * @param  string|list<string>  $secrets
     *
     * @throws InvalidWebhookSignature
     */
    public static function assertValid(
        string $header,
        string $rawBody,
        #[\SensitiveParameter]
        string|array $secrets,
        DateTimeImmutable|int $now,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        string $source = 'webhook',
    ): void {
        if ($toleranceSeconds < 0) {
            throw new InvalidCommand('toleranceSeconds', 'must not be negative', 'webhooks.invalid_tolerance');
        }

        $secretList = self::normalizeSecrets($secrets);
        $parsed = self::parse($header, $source);
        $nowEpoch = $now instanceof DateTimeImmutable ? $now->getTimestamp() : $now;

        if ($toleranceSeconds > 0 && abs($nowEpoch - $parsed->timestamp) > $toleranceSeconds) {
            throw new InvalidWebhookSignature($source, 'signature timestamp is outside the allowed replay window');
        }

        $signedPayload = "{$parsed->timestamp}.{$rawBody}";
        $matched = false;

        foreach ($secretList as $secret) {
            $expected = hash_hmac('sha256', $signedPayload, $secret);

            foreach ($parsed->signatures as $candidate) {
                if (hash_equals($expected, $candidate)) {
                    $matched = true;
                }
            }
        }

        if (! $matched) {
            throw new InvalidWebhookSignature($source, 'HMAC-SHA256 digest mismatch');
        }
    }

    /**
     * @param  string|list<string>  $secrets
     */
    public static function verifyPayload(
        string $header,
        string $rawBody,
        #[\SensitiveParameter]
        string|array $secrets,
    ): bool {
        try {
            self::assertPayloadValid($header, $rawBody, $secrets);

            return true;
        } catch (InvalidWebhookSignature) {
            return false;
        }
    }

    /**
     * @param  string|list<string>  $secrets
     *
     * @throws InvalidWebhookSignature
     */
    public static function assertPayloadValid(
        string $header,
        string $rawBody,
        #[\SensitiveParameter]
        string|array $secrets,
        string $source = 'webhook',
    ): void {
        $secretList = self::normalizeSecrets($secrets);
        $trimmed = trim($header);

        if (str_starts_with(strtolower($trimmed), 'sha256=')) {
            $trimmed = substr($trimmed, 7);
        }

        $candidate = strtolower($trimmed);

        if (preg_match('/\A[0-9a-f]{64}\z/', $candidate) !== 1) {
            throw new InvalidWebhookSignature($source, 'payload signature must be a 64-character hex digest');
        }

        $matched = false;

        foreach ($secretList as $secret) {
            if (hash_equals(hash_hmac('sha256', $rawBody, $secret), $candidate)) {
                $matched = true;
            }
        }

        if (! $matched) {
            throw new InvalidWebhookSignature($source, 'HMAC-SHA256 payload digest mismatch');
        }
    }

    public function toHeader(): string
    {
        $parts = ["t={$this->timestamp}"];

        foreach ($this->signatures as $sig) {
            $parts[] = "v1={$sig}";
        }

        return implode(',', $parts);
    }

    public function __toString(): string
    {
        return $this->toHeader();
    }

    /**
     * @param  string|list<string>  $secrets
     * @return non-empty-list<string>
     */
    private static function normalizeSecrets(#[\SensitiveParameter] string|array $secrets): array
    {
        $list = is_string($secrets) ? [$secrets] : $secrets;
        $valid = [];

        foreach ($list as $secret) {
            if ($secret === '') {
                throw new InvalidCommand('secrets', 'every secret must be a non-empty string', 'webhooks.invalid_secret');
            }

            $valid[] = $secret;
        }

        if ($valid === []) {
            throw new InvalidCommand('secrets', 'at least one secret is required', 'webhooks.invalid_secret');
        }

        return $valid;
    }
}
