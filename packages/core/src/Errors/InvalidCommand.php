<?php

declare(strict_types=1);

namespace Commerz\Errors;

use Commerz\Attributes\Api;

/** A command, query or value object was built with a malformed field. Subclasses narrow the code. */
#[Api(since: '0.1')]
class InvalidCommand extends CommerzException
{
    public function __construct(
        public readonly string $field,
        public readonly string $reason,
        private readonly string $errorCode = 'common.invalid_command',
    ) {
        parent::__construct("Invalid {$field}: {$reason}.");
    }

    public function code(): string
    {
        return $this->errorCode;
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Validation;
    }

    public function context(): array
    {
        return ['field' => $this->field, 'reason' => $this->reason];
    }
}
