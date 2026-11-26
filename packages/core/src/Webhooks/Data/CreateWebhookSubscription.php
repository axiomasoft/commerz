<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class CreateWebhookSubscription
{
    /** @var non-empty-list<string> */
    public array $eventTypes;

    /** @var array<string, string> */
    public array $headers;

    /**
     * @param  list<string>  $eventTypes  patterns such as `'sales.order_placed'`, `'sales.*'`, or `'*'`
     * @param  array<string, string>  $headers  static HTTP headers sent alongside signed system headers
     */
    public function __construct(
        public string $code,
        public string $targetUrl,
        #[\SensitiveParameter]
        public string $signingSecret,
        array $eventTypes,
        public int $maxAttempts = 5,
        public int $failureThreshold = 5,
        array $headers = [],
    ) {
        if (preg_match('/\A[a-z0-9][a-z0-9_.\-]{0,63}\z/', $code) !== 1) {
            throw new InvalidCommand('code', 'must match [a-z0-9][a-z0-9_.-]{0,63}', 'webhooks.invalid_subscription');
        }

        self::assertTargetUrl($targetUrl);
        self::assertSigningSecret($signingSecret);
        $this->eventTypes = self::normalizeEventTypes($eventTypes);

        if ($maxAttempts < 1 || $maxAttempts > 20) {
            throw new InvalidCommand('maxAttempts', 'must be from 1 to 20', 'webhooks.invalid_subscription');
        }

        if ($failureThreshold < 1 || $failureThreshold > 1000) {
            throw new InvalidCommand('failureThreshold', 'must be from 1 to 1000', 'webhooks.invalid_subscription');
        }

        $this->headers = self::normalizeHeaders($headers);
    }

    public static function assertTargetUrl(string $targetUrl): void
    {
        if (strlen($targetUrl) > 2048 || str_contains($targetUrl, "\0") || filter_var($targetUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidCommand('targetUrl', 'must be a valid HTTP/HTTPS URL up to 2048 bytes', 'webhooks.invalid_subscription');
        }

        $scheme = strtolower((string) parse_url($targetUrl, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidCommand('targetUrl', 'must use http or https scheme', 'webhooks.invalid_subscription');
        }
    }

    public static function assertSigningSecret(#[\SensitiveParameter] string $signingSecret): void
    {
        if ($signingSecret === '' || strlen($signingSecret) > 4096 || str_contains($signingSecret, "\0")) {
            throw new InvalidCommand('signingSecret', 'must be 1..4096 bytes without NUL', 'webhooks.invalid_subscription');
        }
    }

    /**
     * @param  list<string>  $eventTypes
     * @return non-empty-list<string>
     */
    public static function normalizeEventTypes(array $eventTypes): array
    {
        if ($eventTypes === []) {
            throw new InvalidCommand('eventTypes', 'must contain at least one event pattern', 'webhooks.invalid_subscription');
        }

        $normalized = [];

        foreach ($eventTypes as $pattern) {
            if (strlen($pattern) > 191 || ! self::isValidPattern($pattern)) {
                throw new InvalidCommand('eventTypes', "invalid event pattern '{$pattern}'", 'webhooks.invalid_subscription');
            }

            $normalized[] = $pattern;
        }

        $unique = array_values(array_unique($normalized));

        return $unique;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public static function normalizeHeaders(array $headers): array
    {
        $clean = [];

        foreach ($headers as $name => $value) {
            if (preg_match('/\A[A-Za-z0-9\-]{1,128}\z/', $name) !== 1) {
                throw new InvalidCommand('headers', 'header names must be valid HTTP token names', 'webhooks.invalid_subscription');
            }

            if (strlen($value) > 2048 || preg_match('/[\r\n\0]/', $value) === 1) {
                throw new InvalidCommand('headers', "header '{$name}' contains invalid characters or exceeds 2048 bytes", 'webhooks.invalid_subscription');
            }

            $clean[$name] = $value;
        }

        return $clean;
    }

    private static function isValidPattern(string $pattern): bool
    {
        if ($pattern === '*') {
            return true;
        }

        if (preg_match('/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+\.\*\z/', $pattern) === 1) {
            return true;
        }

        if (preg_match('/\A[a-z][a-z0-9_]*\.\*\z/', $pattern) === 1) {
            return true;
        }

        return preg_match('/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+\z/', $pattern) === 1;
    }
}
