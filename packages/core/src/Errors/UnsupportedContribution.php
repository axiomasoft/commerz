<?php

declare(strict_types=1);

namespace Commerz\Errors;

use Commerz\Attributes\Api;

/** A plugin contributed a kind or key the installed packages do not support. */
#[Api(since: '0.1')]
final class UnsupportedContribution extends CommerzException
{
    public function __construct(
        public readonly string $kind,
        public readonly string $key,
        public readonly string $reason,
    ) {
        parent::__construct("Unsupported contribution {$kind}/{$key}: {$reason}.");
    }

    public function code(): string
    {
        return 'plugins.unsupported_contribution';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Unsupported;
    }

    public function context(): array
    {
        return ['kind' => $this->kind, 'key' => $this->key, 'reason' => $this->reason];
    }
}
