<?php

declare(strict_types=1);

namespace Commerz\Errors;

use Commerz\Attributes\Api;
use RuntimeException;

/**
 * Root of all package errors. `code()` is a stable machine code `<module>.<snake_reason>`;
 * the human message is for logs and may change.
 */
#[Api(since: '0.1')]
abstract class CommerzException extends RuntimeException
{
    abstract public function code(): string;

    abstract public function category(): ErrorCategory;

    /** @return array<string, scalar|null> */
    abstract public function context(): array;
}
