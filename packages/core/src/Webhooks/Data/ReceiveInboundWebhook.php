<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ReceiveInboundWebhook
{
    /** @var array<string, string> */
    public array $headers;

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $source,
        public string $externalEventId,
        public string $eventType,
        public string $rawBody,
        public string $signature,
        array $headers = [],
    ) {
        if (preg_match('/\A[a-z0-9][a-z0-9_.\-]{0,63}\z/', $source) !== 1) {
            throw new InvalidCommand('source', 'must match [a-z0-9][a-z0-9_.-]{0,63}', 'webhooks.invalid_inbound');
        }

        if (trim($externalEventId) === '' || strlen($externalEventId) > 191 || ! mb_check_encoding($externalEventId, 'UTF-8') || str_contains($externalEventId, "\0")) {
            throw new InvalidCommand('externalEventId', 'must be 1..191 bytes of UTF-8 without NUL', 'webhooks.invalid_inbound');
        }

        if (trim($eventType) === '' || strlen($eventType) > 191 || ! mb_check_encoding($eventType, 'UTF-8') || str_contains($eventType, "\0")) {
            throw new InvalidCommand('eventType', 'must be 1..191 bytes of UTF-8 without NUL', 'webhooks.invalid_inbound');
        }

        if ($rawBody === '' || str_contains($rawBody, "\0")) {
            throw new InvalidCommand('rawBody', 'must be a non-empty JSON body without NUL', 'webhooks.invalid_inbound');
        }

        $cleanHeaders = [];

        foreach ($headers as $name => $value) {
            if ($name === '' || str_contains($name, "\0") || str_contains($value, "\0")) {
                throw new InvalidCommand('headers', 'must map non-empty string header names to strings without NUL', 'webhooks.invalid_inbound');
            }

            $cleanHeaders[$name] = $value;
        }

        $this->headers = $cleanHeaders;
    }
}
