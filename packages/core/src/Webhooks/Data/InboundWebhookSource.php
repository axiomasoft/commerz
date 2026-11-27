<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Webhooks\Enums\InboundSignatureMode;
use Packman\Attributes\Api;
use SensitiveParameter;

/** Configuration for an external webhook sender verified by `InboundWebhookGateway`. */
#[Api(since: '0.1')]
final readonly class InboundWebhookSource
{
    /** @var non-empty-list<string> */
    public array $secrets;

    /**
     * @param  string|list<string>  $secrets  one or more shared HMAC-SHA256 secrets
     */
    public function __construct(
        public string $source,
        #[SensitiveParameter]
        string|array $secrets,
        public InboundSignatureMode $signatureMode = InboundSignatureMode::Timestamped,
        public int $toleranceSeconds = WebhookSignature::DEFAULT_TOLERANCE_SECONDS,
    ) {
        if (preg_match('/\A[a-z0-9][a-z0-9_.\-]{0,63}\z/', $source) !== 1) {
            throw new InvalidCommand('source', 'must match [a-z0-9][a-z0-9_.-]{0,63}', 'webhooks.invalid_source');
        }

        $list = is_string($secrets) ? [$secrets] : $secrets;
        $valid = [];

        foreach ($list as $secret) {
            if ($secret === '' || str_contains($secret, "\0")) {
                throw new InvalidCommand('secrets', 'every secret must be a non-empty string without NUL', 'webhooks.invalid_secret');
            }

            $valid[] = $secret;
        }

        if ($valid === []) {
            throw new InvalidCommand('secrets', 'at least one secret is required', 'webhooks.invalid_secret');
        }

        if ($toleranceSeconds < 0 || $toleranceSeconds > 86400) {
            throw new InvalidCommand('toleranceSeconds', 'must be from 0 to 86400', 'webhooks.invalid_tolerance');
        }

        $this->secrets = $valid;
    }
}
