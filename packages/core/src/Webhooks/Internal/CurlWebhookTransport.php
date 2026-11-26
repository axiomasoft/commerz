<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Internal;

use Commerz\Webhooks\Contracts\WebhookTransport;
use Commerz\Webhooks\Data\OutboundWebhookRequest;
use Commerz\Webhooks\Data\WebhookTransportResponse;
use Commerz\Webhooks\Exceptions\WebhookTransportFailed;
use Override;
use Packman\Attributes\Internal;

#[Internal]
final readonly class CurlWebhookTransport implements WebhookTransport
{
    #[Override]
    public function send(OutboundWebhookRequest $request): WebhookTransportResponse
    {
        $handle = curl_init($request->url);

        if ($handle === false) {
            throw new WebhookTransportFailed($request->url, 'unable to initialize cURL handle');
        }

        $headerLines = [];

        foreach ($request->headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request->body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $request->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(5, $request->timeoutSeconds),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);

        $responseBody = curl_exec($handle);

        if (! is_string($responseBody)) {
            $error = curl_error($handle);
            unset($handle);

            throw new WebhookTransportFailed($request->url, $error !== '' ? $error : 'network request failed');
        }

        $statusCode = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        unset($handle);

        if ($statusCode < 100 || $statusCode > 599) {
            throw new WebhookTransportFailed($request->url, 'invalid HTTP response status code');
        }

        return new WebhookTransportResponse($statusCode, $responseBody);
    }
}
