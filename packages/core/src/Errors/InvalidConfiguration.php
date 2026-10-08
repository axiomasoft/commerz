<?php

declare(strict_types=1);

namespace Commerz\Errors;

use Commerz\Attributes\Api;

/** A configuration value is wrong; `path` is the dotted key, e.g. `commerz.table_prefix`. */
#[Api(since: '0.1')]
final class InvalidConfiguration extends CommerzException
{
    public function __construct(public readonly string $path, public readonly string $reason)
    {
        parent::__construct("Invalid configuration {$path}: {$reason}.");
    }

    public function code(): string
    {
        return 'config.invalid';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Configuration;
    }

    public function context(): array
    {
        return ['path' => $this->path, 'reason' => $this->reason];
    }
}
