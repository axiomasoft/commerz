<?php

declare(strict_types=1);

namespace Commerz\Errors;

use Commerz\Attributes\Api;

/** The same idempotency key was reused for a different request payload. */
#[Api(since: '0.1')]
final class IdempotencyConflict extends CommerzException
{
    public function __construct(public readonly string $operation, public readonly string $key)
    {
        parent::__construct("Idempotency key '{$key}' was already used by '{$operation}' with a different payload.");
    }

    public function code(): string
    {
        return 'common.idempotency_conflict';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['operation' => $this->operation, 'key' => $this->key];
    }
}
